#!/bin/sh
set -eu

if [ "${PLATFORM_NAME:-}" != "iphoneos" ]; then
  exit 0
fi

if [ -z "${DWARF_DSYM_FOLDER_PATH:-}" ]; then
  echo "warning: DWARF_DSYM_FOLDER_PATH is not set; skipping WebRTC dSYM generation"
  exit 0
fi

WEBRTC_BINARY=""
for candidate in \
  "${TARGET_BUILD_DIR:-}/${FRAMEWORKS_FOLDER_PATH:-}/WebRTC.framework/WebRTC" \
  "${PODS_XCFRAMEWORKS_BUILD_DIR:-}/WebRTC-SDK/WebRTC.framework/WebRTC" \
  "${PODS_ROOT:-}/WebRTC-SDK/WebRTC.xcframework/ios-arm64/WebRTC.framework/WebRTC"
do
  if [ -f "$candidate" ]; then
    WEBRTC_BINARY="$candidate"
    break
  fi
done

if [ -z "$WEBRTC_BINARY" ]; then
  echo "warning: WebRTC.framework binary was not found; skipping WebRTC dSYM generation"
  exit 0
fi

DSYM_PATH="${DWARF_DSYM_FOLDER_PATH}/WebRTC.framework.dSYM"
rm -rf "$DSYM_PATH"
mkdir -p "${DWARF_DSYM_FOLDER_PATH}"

xcrun dsymutil "$WEBRTC_BINARY" -o "$DSYM_PATH"

if command -v dwarfdump >/dev/null 2>&1; then
  dwarfdump --uuid "$DSYM_PATH/Contents/Resources/DWARF/WebRTC"
fi
