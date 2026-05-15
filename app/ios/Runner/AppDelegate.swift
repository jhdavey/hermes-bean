import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    GeneratedPluginRegistrant.register(with: self)

    if let controller = window?.rootViewController as? FlutterViewController {
      let platformChannel = FlutterMethodChannel(
        name: "heybean/platform",
        binaryMessenger: controller.binaryMessenger
      )
      platformChannel.setMethodCallHandler { call, result in
        guard call.method == "openUrl" else {
          result(FlutterMethodNotImplemented)
          return
        }
        guard
          let args = call.arguments as? [String: Any],
          let rawUrl = args["url"] as? String,
          let url = URL(string: rawUrl)
        else {
          result(FlutterError(code: "invalid-url", message: "Missing or invalid URL", details: nil))
          return
        }
        UIApplication.shared.open(url, options: [:]) { success in
          result(success)
        }
      }
    }

    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }
}
