import Flutter
import AVFoundation
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate {
  private var platformChannel: FlutterMethodChannel?
  private let speechSynthesizer = AVSpeechSynthesizer()

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
      switch call.method {
      case "openUrl":
        self.handleOpenUrl(call, result: result)
      case "setAppBadge":
        self.handleSetAppBadge(call, result: result)
      case "speakText":
        self.handleSpeakText(call, result: result)
      default:
        result(FlutterMethodNotImplemented)
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

  private func handleOpenUrl(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
    guard
      let args = call.arguments as? [String: Any],
      let rawUrl = args["url"] as? String,
      let url = URL(string: rawUrl)
    else {
      result(FlutterError(code: "invalid-url", message: "Missing or invalid URL", details: nil))
      return
    }

    guard isAllowedExternalUrl(url) else {
      result(FlutterError(code: "blocked-url", message: "URL host is not allowed", details: nil))
      return
    }

    DispatchQueue.main.async {
      UIApplication.shared.open(url, options: [:]) { success in
        result(success)
      }
    }
  }

  private func handleSetAppBadge(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
    guard let args = call.arguments as? [String: Any] else {
      result(FlutterError(code: "invalid-badge", message: "Missing badge arguments", details: nil))
      return
    }

    let rawCount = args["count"] as? Int ?? 0
    let count = max(0, rawCount)

    DispatchQueue.main.async {
      if #available(iOS 16.0, *) {
        UNUserNotificationCenter.current().setBadgeCount(count) { error in
          if let error = error {
            result(FlutterError(code: "badge-failed", message: error.localizedDescription, details: nil))
          } else {
            result(nil)
          }
        }
      } else {
        UIApplication.shared.applicationIconBadgeNumber = count
        result(nil)
      }
    }
  }

  private func handleSpeakText(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
    guard
      let args = call.arguments as? [String: Any],
      let text = args["text"] as? String
    else {
      result(FlutterError(code: "invalid-speech", message: "Missing speech text", details: nil))
      return
    }

    let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
    guard !trimmed.isEmpty else {
      result(nil)
      return
    }

    DispatchQueue.main.async {
      if self.speechSynthesizer.isSpeaking {
        self.speechSynthesizer.stopSpeaking(at: .immediate)
      }
      let utterance = AVSpeechUtterance(string: trimmed)
      utterance.voice = AVSpeechSynthesisVoice(language: "en-US")
      utterance.rate = AVSpeechUtteranceDefaultSpeechRate
      self.speechSynthesizer.speak(utterance)
      result(nil)
    }
  }
}
