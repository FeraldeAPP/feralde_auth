module.exports = {
  apps: [
    {
      name: 'rz-auth',
      script: 'artisan',
      interpreter: 'php',
      args: 'serve --host=0.0.0.0 --port=55001',
      cwd: '/home/ferl/hr/auth',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '500M',
      env: {
        APP_ENV: 'production',
      },
      error_file: './storage/logs/pm2-error.log',
      out_file: './storage/logs/pm2-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      merge_logs: true,
      min_uptime: '10s',
      max_restarts: 10,
      restart_delay: 4000,
    },
  ],
};
