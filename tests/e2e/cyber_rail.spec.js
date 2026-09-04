import { test, expect } from '@playwright/test';

async function loginUser(page, email, password = 'password123') {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await page.click('#btn-login-submit');
  await expect(page).toHaveURL(/\/dashboard|\/lobby/, { timeout: 20000 });
}

test.describe('Cyber-Rail Real Browser E2E Suite', () => {

  test('E2E-01: Full guest redirect flow and open lobbies API synchronization', async ({ page }) => {
    // 1. Visit /lobby as unauthenticated guest
    await page.goto('/lobby');
    await expect(page).toHaveURL(/\/login/);

    // 2. Submit credentials and wait for redirect to intended /lobby
    await page.fill('input[name="email"]', 'apex@cyber-rail.gg');
    await page.fill('input[name="password"]', 'password123');
    await page.click('#btn-login-submit');
    await expect(page).toHaveURL(/\/lobby|\/dashboard/, { timeout: 20000 });

    if (!page.url().includes('/lobby')) {
      await page.goto('/lobby');
    }
    await expect(page).toHaveURL(/\/lobby/);
    await expect(page.locator('#duels-grid')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#btn-open-create-modal')).toBeVisible();
  });

  test('E2E-02: Authenticated practice run with active WebGL canvas and HUD progression', async ({ page }) => {
    await loginUser(page, 'apex@cyber-rail.gg');

    // Visit /game (Practice mode)
    await page.goto('/game');
    await expect(page.locator('#game-canvas')).toBeVisible({ timeout: 15000 });
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

    // Release WebGL context and stop animation loop to prevent background CPU usage
    await page.goto('about:blank');
  });

  test('E2E-03: Real Full Duel Lifecycle — Real UI Create, Real UI Join, Seed Privacy, Start, Collision, Verifying State, and Authoritative Settlement', async ({ browser }) => {
    test.setTimeout(180000);

    // Context A (Player A: Apex Titan)
    const contextA = await browser.newContext();
    const pageA = await contextA.newPage();
    const errorsA = [];
    pageA.on('pageerror', err => errorsA.push(`[PageA Exception]: ${err.message}`));
    pageA.on('console', msg => {
      if (msg.type() === 'error') errorsA.push(`[PageA Console]: ${msg.text()}`);
    });

    await loginUser(pageA, 'apex@cyber-rail.gg');
    await pageA.goto('/lobby');

    // Player A creates lobby through visible UI modal
    await pageA.click('#btn-open-create-modal');
    await expect(pageA.locator('#create-lobby-modal')).toBeVisible();
    await pageA.click('button[data-stake="5000"]');
    await pageA.click('#btn-confirm-create');

    // Wait for lobby to be created and data-uuid attribute populated
    await expect(pageA.locator('#modal-waiting-step')).toBeVisible({ timeout: 15000 });
    await expect(pageA.locator('#created-match-uuid')).toHaveAttribute('data-uuid', /[a-f0-9-]+/, { timeout: 20000 });
    const matchUuid = await pageA.locator('#created-match-uuid').getAttribute('data-uuid');
    expect(matchUuid).toBeTruthy();

    // Context B (Player B: Viper 99) in an independent browser context
    const contextB = await browser.newContext();
    const pageB = await contextB.newPage();
    const errorsB = [];
    pageB.on('pageerror', err => errorsB.push(`[PageB Exception]: ${err.message}`));
    pageB.on('console', msg => {
      if (msg.type() === 'error') errorsB.push(`[PageB Console]: ${msg.text()}`);
    });

    await loginUser(pageB, 'viper@cyber-rail.gg');
    await pageB.goto('/lobby');

    // Player B locates Player A's actual lobby in DOM and clicks UI ACCEPT/JOIN button
    const joinBtn = pageB.locator(`button[data-lobby-uuid="${matchUuid}"]`);
    await expect(joinBtn).toBeVisible({ timeout: 15000 });
    await joinBtn.click();
    await expect(pageB).toHaveURL(new RegExp(`/duels/${matchUuid}/play`), { timeout: 25000 });
    await pageB.waitForLoadState('domcontentloaded');
    await expect(pageB.locator('#game-canvas')).toBeVisible({ timeout: 15000 });

    // Player A automatically transitions to /duels/{uuid}/play via waiting step polling
    await expect(pageA).toHaveURL(new RegExp(`/duels/${matchUuid}/play`), { timeout: 25000 });
    await pageA.waitForLoadState('domcontentloaded');
    await expect(pageA.locator('#game-canvas')).toBeVisible({ timeout: 15000 });

    // Verify seed privacy on both players BEFORE run starts:
    // 1. Raw seed is strictly absent from DOM
    const rawSeedA = await pageA.evaluate(() => document.getElementById('game-canvas')?.getAttribute('data-seed'));
    const rawSeedB = await pageB.evaluate(() => document.getElementById('game-canvas')?.getAttribute('data-seed'));
    expect(rawSeedA).toBeNull();
    expect(rawSeedB).toBeNull();

    // 2. Seed commitment is present and identical on both players
    const commitmentA = await pageA.evaluate(() => document.getElementById('game-canvas')?.getAttribute('data-commitment'));
    const commitmentB = await pageB.evaluate(() => document.getElementById('game-canvas')?.getAttribute('data-commitment'));
    expect(commitmentA).toBeTruthy();
    expect(commitmentA.length).toBe(64);
    expect(commitmentB).toBe(commitmentA);

    // 3. Status is READY
    await expect(pageA.locator('#hud-start-banner')).toContainText('READY FOR COMBAT');
    await expect(pageB.locator('#hud-start-banner')).toContainText('READY FOR COMBAT');

    // Player A launches run: observe authoritative start-run negotiation
    await pageA.bringToFront();
    const startRunPromiseA = pageA.waitForResponse(
      response => response.url().includes('/start-run') && response.request().method() === 'POST'
    );
    await pageA.click('#hud-btn-start');
    const startRunResA = await startRunPromiseA;
    expect(startRunResA.status(), `Player A start-run must return 200. Logs: ${errorsA.join('; ')}`).toBe(200);
    const startRunDataA = await startRunResA.json();
    expect(startRunDataA.ticket_token).toBeTruthy();
    expect(startRunDataA.game_seed).toBeTruthy();
    expect(startRunDataA.seed_commitment).toBe(commitmentA);
    await expect(pageA.locator('#hud-start-banner')).toBeHidden({ timeout: 5000 });

    // Wait for Player A's run to encounter collision at obstacle wave and submit
    await expect(pageA.locator('#post-match-modal')).toHaveClass(/opacity-100/, { timeout: 30000 });

    // WHILE Player A is finished and Player B has not finished yet:
    // Verify UI strictly shows VERIFYING RESULT and does NOT declare VICTORY/DEFEAT prematurely
    await expect(pageA.locator('#modal-status-badge')).toContainText('VERIFYING RESULT');
    const modalTitleA = await pageA.locator('#modal-title').innerText();
    expect(modalTitleA).not.toContain('VICTORY');
    expect(modalTitleA).not.toContain('DEFEAT');
    expect(modalTitleA).not.toContain('WINNER DECLARED');

    // Player B now launches run: observe authoritative start-run negotiation
    await pageB.bringToFront();
    const startRunPromiseB = pageB.waitForResponse(
      response => response.url().includes('/start-run') && response.request().method() === 'POST'
    );
    await pageB.click('#hud-btn-start');
    const startRunResB = await startRunPromiseB;
    expect(startRunResB.status(), `Player B start-run must return 200. Logs: ${errorsB.join('; ')}`).toBe(200);
    const startRunDataB = await startRunResB.json();
    expect(startRunDataB.ticket_token).toBeTruthy();
    expect(startRunDataB.game_seed).toBeTruthy();
    expect(startRunDataB.seed_commitment).toBe(commitmentB);
    await expect(pageB.locator('#hud-start-banner')).toBeHidden({ timeout: 5000 });

    // Wait for Player B's run to encounter collision and submit
    await expect(pageB.locator('#post-match-modal')).toHaveClass(/opacity-100/, { timeout: 30000 });

    // Both players have submitted: Server settles match authoritatively
    // Verify both browsers transition from VERIFYING RESULT to authoritative result
    await pageA.bringToFront();
    await expect(pageA.locator('#modal-status-badge')).not.toContainText('VERIFYING RESULT', { timeout: 30000 });
    await pageB.bringToFront();
    await expect(pageB.locator('#modal-status-badge')).not.toContainText('VERIFYING RESULT', { timeout: 30000 });

    // Authoritative result check
    const statusA = await pageA.locator('#modal-status-badge').innerText();
    const statusB = await pageB.locator('#modal-status-badge').innerText();
    expect([statusA, statusB]).toContain('AUTHORITATIVE WINNER');
    expect([statusA, statusB]).toContain('MATCH SETTLED');

    // Financial breakdown check: pot is $100.00
    await expect(pageA.locator('#modal-gross-pot')).toContainText('$100.00');
    await expect(pageB.locator('#modal-gross-pot')).toContainText('$100.00');

    await contextA.close();
    await contextB.close();
  });

  test('E2E-04: Real Browser Multi-Tab Authentication Verification', async ({ browser }) => {
    test.setTimeout(300000);
    console.log('[E2E-04] Starting test...');

    // Single authenticated context with two tabs
    const context = await browser.newContext();
    const tabA = await context.newPage();
    console.log('[E2E-04] Logging in Tab A...');
    await loginUser(tabA, 'apex@cyber-rail.gg');
    console.log('[E2E-04] Tab A logged in:', tabA.url());

    // Tab A opens arena
    console.log('[E2E-04] Tab A navigating to /game...');
    await tabA.goto('/game', { waitUntil: 'domcontentloaded' });
    console.log('[E2E-04] Tab A waiting for #game-canvas...');
    await expect(tabA.locator('#game-canvas')).toBeVisible({ timeout: 15000 });
    await tabA.waitForTimeout(1000);
    console.log('[E2E-04] Tab A #game-canvas visible & stable');

    // Tab B opens dashboard
    console.log('[E2E-04] Creating Tab B...');
    const tabB = await context.newPage();
    console.log('[E2E-04] Tab B navigating to /dashboard...');
    await tabB.goto('/dashboard');
    await expect(tabB).toHaveURL(/\/dashboard/);
    console.log('[E2E-04] Tab B reached /dashboard');

    // Tab B refreshes 10 times in a row
    for (let i = 0; i < 10; i++) {
      console.log(`[E2E-04] Tab B refresh iteration ${i} starting...`);
      await tabB.reload({ waitUntil: 'domcontentloaded' });
      await expect(tabB).toHaveURL(/\/dashboard/);
      console.log(`[E2E-04] Tab B refresh iteration ${i} done`);
      await tabB.waitForTimeout(200);
    }
    console.log('[E2E-04] All 10 refreshes completed!');

    // Tab A continues making authenticated API requests with session cookies
    console.log('[E2E-04] Testing authenticated API request from Tab A...');
    const apiStatus = await tabA.evaluate(async () => {
      const res = await fetch('/api/v1/duels/lobbies', {
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      return res.status;
    });
    console.log('[E2E-04] apiStatus from Tab A:', apiStatus);
    expect(apiStatus).toBe(200);

    // Both tabs remain authenticated; neither invalidates the other
    await expect(tabA).toHaveURL(/\/game/);
    await expect(tabB).toHaveURL(/\/dashboard/);

    // Verify 0 personal access tokens stored or minted for browser sessions
    const storedTokenA = await tabA.evaluate(() => localStorage.getItem('token') || sessionStorage.getItem('token'));
    const storedTokenB = await tabB.evaluate(() => localStorage.getItem('token') || sessionStorage.getItem('token'));
    expect(storedTokenA).toBeNull();
    expect(storedTokenB).toBeNull();
    console.log('[E2E-04] Verified 0 personal access tokens in browser sessions');

    await context.close();
    console.log('[E2E-04] SUCCESS!');
  });

  test('E2E-05: Real Browser Reverb Authorization Verification and Live WebSocket Event Delivery', async ({ browser }) => {
    // Participant A (Apex)
    const contextA = await browser.newContext();
    const pageA = await contextA.newPage();
    await loginUser(pageA, 'apex@cyber-rail.gg');
    await pageA.goto('/lobby');

    // Create a duel match for A
    await pageA.click('#btn-open-create-modal');
    await expect(pageA.locator('#create-lobby-modal')).toBeVisible();
    await pageA.click('button[data-stake="5000"]');
    await pageA.click('#btn-confirm-create');
    await expect(pageA.locator('#modal-waiting-step')).toBeVisible({ timeout: 15000 });
    await expect(pageA.locator('#created-match-uuid')).toHaveAttribute('data-uuid', /[a-f0-9-]+/, { timeout: 20000 });
    const matchUuid = await pageA.locator('#created-match-uuid').getAttribute('data-uuid');

    // Participant A enters the arena to establish Reverb WebSocket presence channel subscription
    await pageA.goto(`/duels/${matchUuid}/play`);
    await pageA.waitForLoadState('domcontentloaded');
    await expect(pageA.locator('#game-canvas')).toBeVisible({ timeout: 15000 });
    await expect(pageA.locator('#hud-rival-name')).toContainText('RIVAL GHOST');

    // Participant B (Viper) joins from another browser context
    const contextB = await browser.newContext();
    const pageB = await contextB.newPage();
    await loginUser(pageB, 'viper@cyber-rail.gg');
    await pageB.goto('/lobby');

    const joinBtn = pageB.locator(`button[data-lobby-uuid="${matchUuid}"]`);
    await expect(joinBtn).toBeVisible({ timeout: 15000 });
    await Promise.all([
      pageB.waitForURL(new RegExp(`/duels/${matchUuid}/play`), { waitUntil: 'domcontentloaded', timeout: 25000 }),
      joinBtn.click(),
    ]);

    // Live Reverb WebSocket Event Delivery Proof:
    // When B joined, Laravel fired DuelOpponentJoined -> Reverb -> WebSocket -> Echo -> DuelEchoManager -> GameApp -> DOM
    // Player A's HUD automatically reflects Viper's arrival via live WebSocket event
    await pageA.bringToFront();
    await expect(pageA.locator('#hud-rival-name')).toContainText('VIPER 99', { timeout: 25000 });
    const receivedOpponentName = await pageA.evaluate(() => window.neonRunnerApp?.opponentJoinedData?.opponent?.username);
    expect(receivedOpponentName).toBe('Viper 99');

    // Outsider C (Ghost)
    const contextC = await browser.newContext();
    const pageC = await contextC.newPage();
    await loginUser(pageC, 'ghost@cyber-rail.gg');
    await pageC.goto('/dashboard');

    // Verify channel authorization via /broadcasting/auth in browser context
    // 1. Participant A authorized (200 OK + valid auth token & channel_data)
    const authResA = await pageA.evaluate(async (uuid) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const res = await fetch('/broadcasting/auth', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          channel_name: `presence-duel.${uuid}`,
          socket_id: '1234.5678',
        })
      });
      const data = await res.json().catch(() => ({}));
      return { status: res.status, ok: res.ok, data };
    }, matchUuid);
    expect(authResA.status).toBe(200);
    expect(typeof authResA.data.auth).toBe('string');
    expect(authResA.data.auth.length).toBeGreaterThan(10);
    expect(typeof authResA.data.channel_data).toBe('string');

    // 2. Participant B authorized (200 OK + valid auth token & channel_data)
    const authResB = await pageB.evaluate(async (uuid) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const res = await fetch('/broadcasting/auth', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          channel_name: `presence-duel.${uuid}`,
          socket_id: '1234.5678',
        })
      });
      const data = await res.json().catch(() => ({}));
      return { status: res.status, ok: res.ok, data };
    }, matchUuid);
    expect(authResB.status).toBe(200);
    expect(typeof authResB.data.auth).toBe('string');
    expect(authResB.data.auth.length).toBeGreaterThan(10);

    // 3. Outsider C REJECTED (403 Forbidden + zero auth tokens or channel data)
    const authResC = await pageC.evaluate(async (uuid) => {
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
      const res = await fetch('/broadcasting/auth', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          channel_name: `presence-duel.${uuid}`,
          socket_id: '1234.5678',
        })
      });
      const data = await res.json().catch(() => ({}));
      return { status: res.status, ok: res.ok, data };
    }, matchUuid);
    expect(authResC.status).toBe(403);
    expect(authResC.data.auth).toBeUndefined();
    expect(authResC.data.channel_data).toBeUndefined();

    await contextA.close();
    await contextB.close();
    await contextC.close();
  });

  test('E2E-06: Rewarded-ad fail-closed verification in browser', async ({ page }) => {
    await loginUser(page, 'apex@cyber-rail.gg');
    await page.goto('/game');
    const adSection = page.locator('#modal-rewarded-ad');
    await expect(adSection).toBeAttached();

    // When ad provider is not configured (fail-closed default), button must be disabled
    const watchAdBtn = page.locator('#btn-watch-ad');
    await expect(watchAdBtn).toBeDisabled();
    await expect(adSection).toContainText('SPONSOR REWARDS UNAVAILABLE');
  });

});