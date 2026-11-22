# PixelPilot

**AI Image Studio** — your prompts, your vision, one click.

PixelPilot is a Laravel + Vue + Inertia app for creating AI images through the Pixelforge API, with transparent, usage-based pricing.

This README grows with the project. Full setup and deployment instructions land in the docs milestone.

## Stack

- PHP 8.3+, Laravel 13
- Vue 3 (Composition API, TypeScript), Inertia, Tailwind CSS 4
- PostgreSQL

## Quick start

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
npm run build
php artisan serve
```
