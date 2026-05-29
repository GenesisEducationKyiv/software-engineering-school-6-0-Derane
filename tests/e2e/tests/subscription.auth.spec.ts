import { test, expect, type APIRequestContext, type Page } from '@playwright/test';

const API_KEY = process.env.E2E_API_KEY ?? 'playwright-secret';
const TEST_EMAIL = 'auth-e2e@example.com';
const VALID_REPO = 'docker/compose';

const authHeaders = { 'X-API-Key': API_KEY };

async function cleanupSubscriptions(request: APIRequestContext): Promise<void> {
  const res = await request.get('/api/subscriptions', { headers: authHeaders });
  if (!res.ok()) return;
  const list = (await res.json()) as Array<{ id: number }>;
  for (const sub of list) {
    await request.delete(`/api/subscriptions/${sub.id}`, { headers: authHeaders });
  }
}

function emailField(page: Page) {
  return page.getByLabel('Email');
}

function repositoryField(page: Page) {
  return page.getByLabel('Repository');
}

function apiKeyField(page: Page) {
  return page.getByLabel('API Key (optional)');
}

function submitButton(page: Page) {
  return page.getByRole('button', { name: 'Subscribe', exact: true });
}

function messageBanner(page: Page) {
  return page.getByTestId('message');
}

test.beforeEach(async ({ request }) => {
  await cleanupSubscriptions(request);
});

test.afterAll(async ({ request }) => {
  await cleanupSubscriptions(request);
});

test.describe('Homepage (auth enabled)', () => {
  test('rejects subscribe without an API key', async ({ page }) => {
    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await repositoryField(page).fill(VALID_REPO);
    await submitButton(page).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'error');
    await expect(message).toContainText(/api key is required/i);
  });

  test('rejects subscribe with a wrong API key', async ({ page }) => {
    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await repositoryField(page).fill(VALID_REPO);
    await apiKeyField(page).fill('totally-wrong-key');
    await submitButton(page).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'error');
    await expect(message).toContainText(/invalid api key/i);
  });

  test('subscribes, lists, and unsubscribes with the correct API key', async ({ page }) => {
    await page.goto('/');
    await emailField(page).fill(TEST_EMAIL);
    await repositoryField(page).fill(VALID_REPO);
    await apiKeyField(page).fill(API_KEY);
    await submitButton(page).click();

    const message = messageBanner(page);
    await expect(message).toHaveAttribute('data-state', 'success');
    await expect(message).toHaveText(`Subscribed to ${VALID_REPO}!`);

    const items = page.getByTestId('subscription-item');
    await expect(items).toHaveCount(1);
    await expect(page.getByTestId('subscription-repo')).toHaveText(VALID_REPO);

    await page.getByRole('button', { name: `Unsubscribe from ${VALID_REPO}` }).click();
    await expect(message).toHaveAttribute('data-state', 'success');
    await expect(message).toHaveText('Unsubscribed successfully');
    await expect(page.getByTestId('subscriptions-section')).toBeHidden();
  });
});
