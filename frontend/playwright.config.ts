import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
  testDir:'tests/browser', fullyParallel:false, workers:1, retries:0,
  use:{baseURL:'http://localhost:8006',trace:'retain-on-failure'},
  projects:[{name:'desktop',use:{...devices['Desktop Chrome'],channel:process.env.PLAYWRIGHT_CHANNEL || 'msedge'}},
    {name:'mobile',use:{...devices['Desktop Chrome'],viewport:{width:390,height:844},isMobile:true,hasTouch:true,channel:process.env.PLAYWRIGHT_CHANNEL || 'msedge'}}],
  webServer:{command:'npm run dev -- --port 8006',url:'http://localhost:8006',reuseExistingServer:false,timeout:30000},
});
