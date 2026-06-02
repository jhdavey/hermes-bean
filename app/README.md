# HeyBean Flutter App

## Firebase Cloud Messaging Setup

Create one Firebase project for HeyBean, then add both mobile apps:

- Android package name: `com.heybean.heybeanapp`
- iOS bundle ID: `com.heybean.heybeanapp`

In Firebase Console:

1. Open Project settings > General and create/register the Android and iOS apps.
2. Copy the Firebase project ID, Web API key, sender ID, Android app ID, and iOS app ID.
3. Open Project settings > Service accounts and generate a new private key for Laravel.
4. Open Project settings > Cloud Messaging and make sure the Firebase Cloud Messaging API is enabled.
5. For iOS, upload an APNs authentication key in Cloud Messaging > Apple app configuration.

Laravel needs the server-side service account:

```env
FIREBASE_PROJECT_ID=your-firebase-project-id
FIREBASE_CREDENTIALS_PATH=/absolute/path/to/firebase-service-account.json
FIREBASE_CREDENTIALS_JSON=
```

Use `FIREBASE_CREDENTIALS_PATH` in production when possible. Use `FIREBASE_CREDENTIALS_JSON` only if your host requires storing the JSON directly as an environment variable.

Flutter reads Firebase client configuration from dart-defines:

```sh
flutter run \
  --dart-define=FIREBASE_API_KEY=... \
  --dart-define=FIREBASE_PROJECT_ID=... \
  --dart-define=FIREBASE_MESSAGING_SENDER_ID=... \
  --dart-define=FIREBASE_STORAGE_BUCKET=... \
  --dart-define=FIREBASE_ANDROID_APP_ID=... \
  --dart-define=FIREBASE_IOS_APP_ID=... \
  --dart-define=FIREBASE_IOS_BUNDLE_ID=com.heybean.heybeanapp
```

Blank Firebase dart-defines are allowed for local builds; push registration simply stays disabled.
