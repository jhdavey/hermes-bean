import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate {
  private var platformChannel: FlutterMethodChannel?

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    GeneratedPluginRegistrant.register(with: self)
    let launched = super.application(application, didFinishLaunchingWithOptions: launchOptions)
    registerHeyBeanPlatformChannel()
    return launched
  }

  private func registerHeyBeanPlatformChannel() {
    guard platformChannel == nil else { return }
    guard let controller = window?.rootViewController as? FlutterViewController else { return }

    let channel = FlutterMethodChannel(
      name: "heybean/platform",
      binaryMessenger: controller.binaryMessenger
    )
    channel.setMethodCallHandler { call, result in
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

      guard self.isAllowedExternalUrl(url) else {
        result(FlutterError(code: "blocked-url", message: "URL host is not allowed", details: nil))
        return
      }

      DispatchQueue.main.async {
        UIApplication.shared.open(url, options: [:]) { success in
          result(success)
        }
      }
    }
    platformChannel = channel
  }

  private func isAllowedExternalUrl(_ url: URL) -> Bool {
    guard url.scheme?.lowercased() == "https" else { return false }
    guard let host = url.host?.lowercased() else { return false }
    return [
      "heybean.org",
      "accounts.google.com",
      "oauth2.googleapis.com",
      "calendar.google.com",
      "www.googleapis.com",
    ].contains(host)
  }
}
