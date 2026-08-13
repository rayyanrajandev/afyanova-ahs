# Afyanova AHS

Afyanova AHS is a Laravel and Vue based hospital information system for private hospital workflows. The system is being developed as a modular platform, with patient registration as the first live testing focus.

## Current Pilot Focus

The initial hospital testing phase should focus on:

- User login and access control
- Patient registration
- Patient search
- Patient profile review
- Basic auditability of user activity

Other modules are present in the codebase, but they should be enabled for hospital testing gradually after patient registration is stable.

## Technology Stack

- Laravel 13
- Vue 3
- Inertia.js
- PostgreSQL
- Vite
- Docker deployment support

## Development Workflow

Local development happens on a developer machine. Staging deployment happens from GitHub to a hosted server.

Typical workflow:

```powershell
git add .
git commit -m "Describe the change"
git push
```

The hosting provider can then redeploy the latest code from the `main` branch.

## Free Testing Deployment

This repository includes Docker-based deployment files for a free or low-cost staging environment.

See:

- `FREE_HOSTING_SETUP.md`
- `.env.staging.example`
- `Dockerfile`

Recommended early testing setup:

- App hosting: Docker-capable free host
- Database: Neon free PostgreSQL
- Repository: private GitHub repository

## Safety Notes

This project may handle sensitive hospital and patient information. For any hospital pilot:

- Keep the GitHub repository private.
- Do not commit `.env` files.
- Use `APP_DEBUG=false` on hosted environments.
- Use HTTPS only.
- Use strong passwords.
- Back up the database before schema changes.
- Avoid using temporary tunnels for real hospital workflows.

Free hosting is acceptable for early testing, but production use should move to a paid and properly backed-up environment.

## Design & Engineering Codex

The previous flat 16-volume list has been replaced by a layered, 4-tier codex that enforces a one-way dependency direction (foundations → platform → workspaces → delivery). This prevents the drift that made the old frontend a mess.

- **Codex index:** [`documents/codex/README.md`](./documents/codex/README.md)
- **Volume 0.1 (start here):** [`documents/codex/00-foundations/01-philosophy-and-2027-ux-principles.md`](./documents/codex/00-foundations/01-philosophy-and-2027-ux-principles.md)
- **Architecture Decision Records:** [`documents/codex/adr/`](./documents/codex/adr/)

Rules of the codex: principles before pixels, tokens before components, platform before workspaces, one volume = one PR, no workspace invents its own design.
