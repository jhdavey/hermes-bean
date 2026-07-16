# HeyBean

HeyBean is a shared productivity product with a Flutter mobile app and a Laravel web app/API. It brings calendars, tasks, reminders, notes, workspaces, notifications, billing, and account management into one place.

## Project layout

- `app/` — Flutter application for iOS, Android, macOS, Windows, Linux, and web.
- `web/` — Laravel API, browser application, public site, and billing/integration services.
- `scripts/` — deployment helpers.

## Laravel development

```bash
cd web
composer install
npm install
composer run dev
```

Run verification with:

```bash
php artisan test
npm test
npm run build
```

## Flutter development

```bash
cd app
flutter pub get
flutter run
```

Run verification with:

```bash
flutter analyze
flutter test
```

## Product design

HeyBean uses a calm natural palette, rounded surfaces, clear hierarchy, and direct controls. Calendar, task, reminder, note, and workspace data are managed explicitly through the interface and synchronized through the Laravel API.
