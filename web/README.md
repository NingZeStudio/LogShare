# mclo.gs Frontend (Vue 3)

This is the new Vue 3 frontend for mclo.gs.

## Project Setup

```sh
npm install
```

## Development

To start the development server:

```sh
npm run dev
```

This will run on `http://localhost:5173`.
It proxies `/api` requests to `http://127.0.0.1:9300` (the PHP backend).
Ensure the PHP backend is running via Docker.

## Build

To build for production:

```sh
npm run build
```

The output will be in `dist/`.

## Deployment

Serve the `dist` directory with Nginx or another static file server.
Configure your web server to redirect 404s to `index.html` (SPA routing).