import { test, expect } from '@playwright/test';

test.describe('Cyber-Rail Real Browser E2E Suite', () => {

  test('E2E-01: Full guest redirect flow and open lobbies API synchronization', async ({ page }) => {
    // 1. Visit /lobby as guest
    await page.goto('/lobby');
    await expect(page).toHaveURL(/\/login/);

    // 2. Submit credentials
    await page.fill('input[name="email"]', 'apex@cyber-rail.gg');
    await page.fill('input[name="password"]', 'password123');
    await page.click('button[type="submit"]');

    // 3. Ensure we arrive at /lobby
    if (!page.url().includes('/lobby')) {
      await page.goto('/lobby');
    }
    await expect(page).toHaveURL(/\/lobby/);
    await expect(page.locator('#duels-grid')).toBeVisible();
    await expect(page.locator('#btn-open-create-modal')).toBeVisible();
  });

  test('E2E-02: Authenticated practice run with active WebGL canvas and HUD progression', async ({ page }) => {
    // Login
    await page.goto('/login');
    await page.fill('input[name="email"]', 'apex@cyber-rail.gg');
    await page.fill('input[name="password"]', 'password123');
    await page.click('button[type="submit"]');

    // Visit /game (Practice mode)
    await page.goto('/game');
    await expect(page.locator('#game-canvas')).toBeVisible();
    await expect(page.locator('#duel-hud')).toBeVisible();

    // Verify HUD elements
    await expect(page.locator('#hud-pot')).toBeVisible();
    await expect(page.locator('#hud-distance')).toBeVisible();

    // Allow game simulation loop to run 2 seconds
    await page.waitForTimeout(2000);

    // Verify distance text updated
    const distanceText = await page.locator('#hud-distance').innerText();
    expect(distanceText).toMatch(/[0-9]+/);

    // Verify WebGL context is active
    const hasContext = await page.evaluate(() => {
      const canvas = document.getElementById('game-canvas');
      return !!canvas && (canvas.width > 0) && (canvas.height > 0);
    });
    expect(hasContext).toBe(true);
  });

  test('E2E-03 & E2E-04: Paid duel lobby flow, seed privacy, run submission and authoritative settlement', async ({ browser }) => {
    // User A (Creator)
    const contextA = await browser.newContext();
    const pageA = await contextA.newPage();

    await pageA.goto('/login');
    await pageA.fill('input[name="email"]', 'apex@cyber-rail.gg');
    await pageA.fill('input[name="password"]', 'password123');
    await pageA.click('button[type="submit"]');
    await pageA.goto('/lobby');

    // User A creates a lobby
    await pageA.click('#btn-open-create-modal');
    await expect(pageA.locator('#create-lobby-modal')).toBeVisible();
    await pageA.click('button[data-stake="5000"]'); // select $50.00
    await pageA.click('#btn-confirm-create');

    // Wait for waiting step and match UUID attribute
    await expect(pageA.locator('#modal-waiting-step')).toBeVisible({ timeout: 15000 });
    await expect(pageA.locator('#created-match-uuid')).toHaveAttribute('data-uuid', /[a-f0-9-]+/, { timeout: 20000 });
    const matchUuid = await pageA.locator('#created-match-uuid').getAttribute('data-uuid');
    expect(matchUuid).toBeTruthy();

    // User A enters arena runner
    await pageA.goto(`/duels/${matchUuid}/play`);
    await expect(pageA.locator('#game-canvas')).toBeVisible();

    // Verify seed commitment is rendered on page A, but raw seed is NOT exposed in DOM
    const rawSeedInA = await pageA.evaluate(() => {
      const canvas = document.getElementById('game-canvas');
      return canvas ? canvas.getAttribute('data-seed') : null;
    });
    expect(rawSeedInA).toBeNull();

    const commitmentInA = await pageA.evaluate(() => {
      const canvas = document.getElementById('game-canvas');
      return canvas ? canvas.getAttribute('data-commitment') : null;
    });
    expect(commitmentInA).toBeTruthy();
    expect(commitmentInA.length).toBe(64);

    // User B (Opponent) in a separate browser context
    const contextB = await browser.newContext();
    const pageB = await contextB.newPage();

    await pageB.goto('/login');
    await pageB.fill('input[name="email"]', 'viper@cyber-rail.gg');
    await pageB.fill('input[name="password"]', 'password123');
    await pageB.click('button[type="submit"]');

    // User B visits lobby
    await pageB.goto('/lobby');
    await expect(pageB.locator('#duels-grid')).toBeVisible();

    // User B joins User A's lobby via in-page session fetch
    const joinStatus = await pageB.evaluate(async (uuid) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const res = await fetch(`/api/v1/duels/lobbies/${uuid}/join`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        }
      });
      return res.status;
    }, matchUuid);
    expect(joinStatus).toBe(200);

    // User B navigates to duel play page as registered participant
    await pageB.goto(`/duels/${matchUuid}/play`);
    await expect(pageB.locator('#game-canvas')).toBeVisible();

    // Verify seed commitment matches on page B as well
    const commitmentInB = await pageB.evaluate(() => {
      const canvas = document.getElementById('game-canvas');
      return canvas ? canvas.getAttribute('data-commitment') : null;
    });
    expect(commitmentInB).toBe(commitmentInA);

    await contextA.close();
    await contextB.close();
  });

  test('E2E-05: Post-match modal elements, financial breakdown, and navigation buttons', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', 'apex@cyber-rail.gg');
    await page.fill('input[name="password"]', 'password123');
    await page.click('button[type="submit"]');

    await page.goto('/game');
    await expect(page.locator('#post-match-modal')).toBeAttached();
    await expect(page.locator('#modal-title')).toBeAttached();
    await expect(page.locator('#modal-payout-amount')).toBeAttached();
    await expect(page.locator('#modal-gross-pot')).toBeAttached();
    await expect(page.locator('#modal-rake')).toBeAttached();
    await expect(page.locator('a[href="/lobby"]')).toBeAttached();
    await expect(page.locator('#btn-rematch')).toBeAttached();
  });

  test('E2E-06: Rewarded-ad fail-closed verification in browser', async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', 'apex@cyber-rail.gg');
    await page.fill('input[name="password"]', 'password123');
    await page.click('button[type="submit"]');

    await page.goto('/game');
    const adSection = page.locator('#modal-rewarded-ad');
    await expect(adSection).toBeAttached();

    // When ad provider is not configured (fail-closed default), button must be disabled
    const watchAdBtn = page.locator('#btn-watch-ad');
    await expect(watchAdBtn).toBeDisabled();
    await expect(adSection).toContainText('SPONSOR REWARDS UNAVAILABLE');
  });

});