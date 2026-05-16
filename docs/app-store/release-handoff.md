# HeyBean Release / Archive Handoff

This file records the local release state before App Store Connect setup and final archive.

## Current Git State

- Branch: `main`
- Latest verified pushed commit before this doc: `970002d`
- Production Forge checkout verified at: `970002d`

## iOS Project State

- Display name: `HeyBean`
- Bundle ID: `com.hermesbean.hermesBeanApp`
- Version from Flutter: `1.0.0`
- Build number from Flutter: `1`
- Minimum iOS deployment target: `13.0`
- iOS target family: iPhone only (`TARGETED_DEVICE_FAMILY = 1`)
- App icon set: generated HeyBean/Bean icon, RGB/no-alpha App Store-safe source.
- Privacy manifest: `app/ios/Runner/PrivacyInfo.xcprivacy`
- Legal links in app:
  - Privacy: https://heybean.org/privacy
  - Terms: https://heybean.org/terms
  - Support: https://heybean.org/support

## Local Signing Readiness

Current check on this Mac:

```bash
security find-identity -v -p codesigning
```

Result at prep time: `0 valid identities found`.

That means the Mac is not yet ready to create the signed App Store archive. Harley should sign into Xcode with the Apple Developer account and ensure Apple Distribution signing/provisioning is available.

## Final Commands To Run After Apple Account Setup

From repo root:

```bash
cd /Users/joshuadavey/development/projects/hermes-bean

git status --short
cd app
flutter clean
flutter pub get
flutter analyze
flutter test
flutter build ios --release --no-codesign
```

Then either archive in Xcode:

```bash
open ios/Runner.xcworkspace
```

In Xcode:

1. Select `Runner` scheme.
2. Select `Any iOS Device (arm64)` or generic iOS device.
3. Product → Archive.
4. Distribute App → App Store Connect → Upload.

Or use Flutter/Xcode CLI once signing is configured:

```bash
flutter build ipa --release --build-name=1.0.0 --build-number=1
```

If CLI export requires an ExportOptions plist, generate it from Xcode once or add one under `app/ios/ExportOptions.plist` with the correct Apple team ID.

## Store Setup Items Harley Must Complete

- Apple Developer/App Store Connect login and 2FA.
- Agreements, tax, and banking if prompted.
- Create App Store Connect app with bundle ID matching the project, or tell Hermes the selected bundle ID before archive so the project can be updated.
- Add listing copy from `docs/app-store/app-store-connect-listing.md`.
- Add privacy/support/marketing URLs.
- Fill App Privacy questionnaire.
- Upload screenshots.
- Decide if a demo account is needed and enter it only in App Store Connect Review Notes.

## Do Not Commit

- Apple ID password or 2FA codes.
- App Store Connect demo credentials.
- Certificates/private keys/provisioning profiles unless intentionally managed with a secure tool such as match in a private encrypted repository.
- Android upload keystore or `android/key.properties`.
