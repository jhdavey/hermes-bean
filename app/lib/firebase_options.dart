import 'dart:io';

import 'package:firebase_core/firebase_core.dart';

class HeyBeanFirebaseOptions {
  static const String apiKey = String.fromEnvironment('FIREBASE_API_KEY');
  static const String projectId = String.fromEnvironment('FIREBASE_PROJECT_ID');
  static const String messagingSenderId = String.fromEnvironment(
    'FIREBASE_MESSAGING_SENDER_ID',
  );
  static const String storageBucket = String.fromEnvironment(
    'FIREBASE_STORAGE_BUCKET',
  );
  static const String androidAppId = String.fromEnvironment(
    'FIREBASE_ANDROID_APP_ID',
  );
  static const String iosAppId = String.fromEnvironment('FIREBASE_IOS_APP_ID');
  static const String iosBundleId = String.fromEnvironment(
    'FIREBASE_IOS_BUNDLE_ID',
    defaultValue: 'com.heybean.heybeanapp',
  );

  static bool get configured =>
      apiKey.isNotEmpty &&
      projectId.isNotEmpty &&
      messagingSenderId.isNotEmpty &&
      _platformAppId.isNotEmpty;

  static FirebaseOptions get currentPlatform {
    final appId = _platformAppId;
    if (!configured) {
      throw StateError('Firebase is not configured for this build.');
    }

    return FirebaseOptions(
      apiKey: apiKey,
      appId: appId,
      messagingSenderId: messagingSenderId,
      projectId: projectId,
      storageBucket: storageBucket.isEmpty ? null : storageBucket,
      iosBundleId: Platform.isIOS ? iosBundleId : null,
    );
  }

  static String get _platformAppId {
    if (Platform.isIOS || Platform.isMacOS) return iosAppId;
    if (Platform.isAndroid) return androidAppId;
    return '';
  }
}
