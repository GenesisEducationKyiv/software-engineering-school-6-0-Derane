import { test, expect, type APIRequestContext } from '@playwright/test';

const TEST_EMAIL = 'e2e@example.com';
const VALID_REPO = 'docker/compose';

async function cleanupSubscriptions(request: APIRequestContext): Promise<void> {
  const res = await request.get('/api/subscriptions');
  if (!res.ok()) return;
  const list = (await res.json()) as Array<{ id: number }>;
  for (const sub of list) {
    await request.delete(`/api/subscriptions/${sub.id}`);
  }
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
    await expect(page.locator('#email')).toBeVisible();
    await expect(page.locator('#repository')).toBeVisible();
    await expect(page.locator('#submitBtn')).toBeEnabled();
    await expect(page.locator('#subscriptionsSection')).toBeHidden();
  });

  test('subscribes to a valid repository and shows success message', async ({ page }) => {
    await page.goto('/');
    await page.fill('#email', TEST_EMAIL);
    await page.fill('#repository', VALID_REPO);
    await page.click('#submitBtn');

    const message = page.locator('#message');
    await expect(message).toHaveClass(/success/);
    await expect(message).toHaveText(`Subscribed to ${VALID_REPO}!`);

    const list = page.locator('#subscriptionsList .sub-item');
    await expect(list).toHaveCount(1);
    await expect(list.locator('.repo')).toHaveText(VALID_REPO);
    await expect(page.locator('#subscriptionsSection')).toBeVisible();
  });

  test('shows error for invalid repository format', async ({ page }) => {
    await page.goto('/');
    await page.fill('#email', TEST_EMAIL);
    await page.fill('#repository', 'not-a-repo');
    await page.click('#submitBtn');

    const message = page.locator('#message');
    await expect(message).toHaveClass(/error/);
    await expect(message).toContainText('repository');
    await expect(page.locator('#subscriptionsSection')).toBeHidden();
  });

  test('shows error for non-existent GitHub repository', async ({ page }) => {
    await page.goto('/');
    await page.fill('#email', TEST_EMAIL);
    await page.fill('#repository', 'nonexistent999/repo999');
    await page.click('#submitBtn');

    const message = page.locator('#message');
    await expect(message).toHaveClass(/error/);
    await expect(message).toContainText(/not found|404/i);
    await expect(page.locator('#subscriptionsSection')).toBeHidden();
  });

  test('loads subscriptions on email blur', async ({ page, request }) => {
    const res = await request.post('/api/subscriptions', {
      data: { email: TEST_EMAIL, repository: VALID_REPO },
    });
    expect(res.ok()).toBeTruthy();

    await page.goto('/');
    await page.fill('#email', TEST_EMAIL);
    await page.locator('#email').blur();

    await expect(page.locator('#subscriptionsSection')).toBeVisible();
    const items = page.locator('#subscriptionsList .sub-item');
    await expect(items).toHaveCount(1);
    await expect(items.locator('.repo')).toHaveText(VALID_REPO);
  });

  test('unsubscribes via the delete button', async ({ page, request }) => {
    const createRes = await request.post('/api/subscriptions', {
      data: { email: TEST_EMAIL, repository: VALID_REPO },
    });
    expect(createRes.ok()).toBeTruthy();

    await page.goto('/');
    await page.fill('#email', TEST_EMAIL);
    await page.locator('#email').blur();
    await expect(page.locator('#subscriptionsList .sub-item')).toHaveCount(1);

    await page.click('#subscriptionsList .btn-delete');

    const message = page.locator('#message');
    await expect(message).toHaveClass(/success/);
    await expect(message).toHaveText('Unsubscribed successfully');
    await expect(page.locator('#subscriptionsSection')).toBeHidden();
  });
});
