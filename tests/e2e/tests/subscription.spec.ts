import { test, expect, type APIRequestContext, type Page } from '@playwright/test';

const TEST_EMAIL = 'e2e@example.com';
const VALID_REPO = 'docker/compose';
const VALID_REPO_ALT = 'golang/go';

async function cleanupSubscriptions(request: APIRequestContext): Promise<void> {
  const res = await request.get('/api/subscriptions');
  if (!res.ok()) return;
  const list = (await res.json()) as Array<{ id: number }>;
  for (const sub of list) {
    await request.delete(`/api/subscriptions/${sub.id}`);
  }
}

async function seedSubscription(
  request: APIRequestContext,
  email: string,
  repository: string,
): Promise<void> {
  const res = await request.post('/api/subscriptions', { data: { email, repository } });
  expect(res.ok()).toBeTruthy();
}

function emailField(page: Page) {
  return page.getByLabel('Email');
}

function repositoryField(page: Page) {
  return page.getByLabel('Repository');
}

function submitButton(page: Page) {
  return page.getByRole('button', { name: 'Subscribe', exact: true });
}

function messageBanner(page: Page) {
  return page.getByTestId('message');
}

function subscriptionsSection(page: Page) {
  return page.getByTestId('subscriptions-section');
}

function subscriptionItems(page: Page) {
  return page.getByTestId('subscription-item');
}

test.beforeEach(async ({ request }) => {
  await cleanupSubscriptions(request);
});

test.afterAll(async ({ request }) => {
  await cleanupSubscriptions(request);
});

test.describe('Homepage', () => {
  test('renders the subscription form', async ({ page }) => {
    await page.goto('/');
    await expect(page).toHaveTitle('GitHub Release Notifier');
    await expect(emailField(page)).toBeVisible();
    await expect(repositoryField(page)).toBeVisible();
    await expect(submitButton(page)).toBeEnabled();
    await expect(subscriptionsSection(page)).toBeHidden();
  });

  test('subscribes to a valid repository and shows success message', async ({ page }) => {
    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await repositoryField(page).fill(VALID_REPO);
    await submitButton(page).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'success');
    await expect(message).toHaveText(`Subscribed to ${VALID_REPO}!`);

    await expect(subscriptionItems(page)).toHaveCount(1);
    await expect(page.getByTestId('subscription-repo')).toHaveText(VALID_REPO);
    await expect(subscriptionsSection(page)).toBeVisible();
  });

  test('shows error for invalid repository format', async ({ page }) => {
    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await repositoryField(page).fill('not-a-repo');
    await submitButton(page).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'error');
    await expect(message).toContainText('repository');
    await expect(subscriptionsSection(page)).toBeHidden();
  });

  test('shows error for non-existent GitHub repository', async ({ page }) => {
    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await repositoryField(page).fill('nonexistent999/repo999');
    await submitButton(page).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'error');
    await expect(message).toContainText(/not found|404/i);
    await expect(subscriptionsSection(page)).toBeHidden();
  });

  test('loads subscriptions on email blur', async ({ page, request }) => {
    await seedSubscription(request, TEST_EMAIL, VALID_REPO);

    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await emailField(page).blur();

    await expect(subscriptionsSection(page)).toBeVisible();
    await expect(subscriptionItems(page)).toHaveCount(1);
    await expect(page.getByTestId('subscription-repo')).toHaveText(VALID_REPO);
  });

  test('unsubscribes via the delete button', async ({ page, request }) => {
    await seedSubscription(request, TEST_EMAIL, VALID_REPO);

    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await emailField(page).blur();
    await expect(subscriptionItems(page)).toHaveCount(1);

    await page.getByRole('button', { name: `Unsubscribe from ${VALID_REPO}` }).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'success');
    await expect(message).toHaveText('Unsubscribed successfully');
    await expect(subscriptionsSection(page)).toBeHidden();
  });

  test('duplicate subscribe stays idempotent in the UI', async ({ page }) => {
    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await repositoryField(page).fill(VALID_REPO);
    await submitButton(page).click();
    await expect(messageBanner(page)).toHaveAttribute('data-state', 'success');
    await expect(subscriptionItems(page)).toHaveCount(1);

    await submitButton(page).click();
    await expect(messageBanner(page)).toHaveAttribute('data-state', 'success');
    await expect(messageBanner(page)).toHaveText(`Subscribed to ${VALID_REPO}!`);
    await expect(subscriptionItems(page)).toHaveCount(1);
  });

  test('shows multiple subscriptions for the same email', async ({ page, request }) => {
    await seedSubscription(request, TEST_EMAIL, VALID_REPO);
    await seedSubscription(request, TEST_EMAIL, VALID_REPO_ALT);

    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await emailField(page).blur();

    await expect(subscriptionItems(page)).toHaveCount(2);
    await expect(page.getByTestId('subscription-repo')).toHaveText([VALID_REPO, VALID_REPO_ALT]);
  });

  test('deleting one item leaves the others in place', async ({ page, request }) => {
    await seedSubscription(request, TEST_EMAIL, VALID_REPO);
    await seedSubscription(request, TEST_EMAIL, VALID_REPO_ALT);

    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await emailField(page).blur();
    await expect(subscriptionItems(page)).toHaveCount(2);

    await page.getByRole('button', { name: `Unsubscribe from ${VALID_REPO}` }).click();

    await expect(messageBanner(page)).toHaveAttribute('data-state', 'success');
    await expect(subscriptionsSection(page)).toBeVisible();
    await expect(subscriptionItems(page)).toHaveCount(1);
    await expect(page.getByTestId('subscription-repo')).toHaveText(VALID_REPO_ALT);
  });

  test('surfaces an error when loading subscriptions fails', async ({ page, request }) => {
    await seedSubscription(request, TEST_EMAIL, VALID_REPO);

    await page.route('**/api/subscriptions?email=*', (route) => {
      if (route.request().method() === 'GET') {
        return route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ error: 'boom' }),
        });
      }
      return route.fallback();
    });

    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await emailField(page).blur();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'error');
    await expect(message).toContainText(/failed to load/i);
    await expect(subscriptionsSection(page)).toBeHidden();
  });

  test('surfaces an error when deleting a subscription fails', async ({ page, request }) => {
    await seedSubscription(request, TEST_EMAIL, VALID_REPO);

    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await emailField(page).blur();
    await expect(subscriptionItems(page)).toHaveCount(1);

    await page.route('**/api/subscriptions/*', (route) => {
      if (route.request().method() === 'DELETE') {
        return route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ error: 'cannot delete right now' }),
        });
      }
      return route.fallback();
    });

    await page.getByRole('button', { name: `Unsubscribe from ${VALID_REPO}` }).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'error');
    await expect(message).toContainText('cannot delete right now');
    await expect(subscriptionItems(page)).toHaveCount(1);
  });
});
