import 'dart:convert';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

class BeanApiException implements Exception {
  const BeanApiException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class BeanApiClient {
  BeanApiClient({String? baseUrl, http.Client? httpClient})
    : baseUrl =
          (baseUrl ??
                  const String.fromEnvironment(
                    'HEYBEAN_API_URL',
                    defaultValue: 'https://heybean.org/api',
                  ))
              .replaceFirst(RegExp(r'/+$'), ''),
      _http = httpClient ?? http.Client();

  final String baseUrl;
  final http.Client _http;
  String? token;

  Future<Map<String, dynamic>> login(String email, String password) =>
      post('/auth/login', {'email': email, 'password': password});

  Future<Map<String, dynamic>> register({
    required String name,
    required String email,
    required String password,
  }) => post('/auth/register', {
    'name': name,
    'email': email,
    'password': password,
    'password_confirmation': password,
    'theme_mode': 'auto',
  });

  Future<dynamic> get(String path) => request('GET', path);
  Future<Map<String, dynamic>> post(
    String path, [
    Map<String, dynamic>? body,
  ]) async => _map(await request('POST', path, body: body));
  Future<Map<String, dynamic>> patch(
    String path, [
    Map<String, dynamic>? body,
  ]) async => _map(await request('PATCH', path, body: body));
  Future<void> delete(String path, [Map<String, dynamic>? body]) async =>
      request('DELETE', path, body: body);

  Future<dynamic> request(
    String method,
    String path, {
    Map<String, dynamic>? body,
  }) async {
    final uri = Uri.parse('$baseUrl$path');
    final headers = <String, String>{
      'Accept': 'application/json',
      if (token?.isNotEmpty == true) 'Authorization': 'Bearer $token',
      if (body != null) 'Content-Type': 'application/json',
    };
    final encoded = body == null ? null : jsonEncode(body);
    late http.Response response;
    switch (method) {
      case 'POST':
        response = await _http.post(uri, headers: headers, body: encoded);
      case 'PATCH':
        response = await _http.patch(uri, headers: headers, body: encoded);
      case 'DELETE':
        response = await _http.delete(uri, headers: headers, body: encoded);
      default:
        response = await _http.get(uri, headers: headers);
    }
    final payload = response.body.isEmpty ? null : jsonDecode(response.body);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      final map = payload is Map
          ? Map<String, dynamic>.from(payload)
          : const <String, dynamic>{};
      final nested = map['error'] is Map
          ? Map<String, dynamic>.from(map['error'])
          : const <String, dynamic>{};
      throw BeanApiException(
        (nested['message'] ??
                map['message'] ??
                'Request failed (${response.statusCode})')
            .toString(),
        statusCode: response.statusCode,
      );
    }
    if (payload is Map && payload.containsKey('data')) return payload['data'];
    return payload;
  }

  Future<Map<String, dynamic>> submitIssue({
    required String message,
    String? pageUrl,
    Uint8List? screenshot,
    String screenshotName = 'screenshot.png',
  }) async {
    final request =
        http.MultipartRequest('POST', Uri.parse('$baseUrl/issue-reports'))
          ..headers['Accept'] = 'application/json'
          ..headers['Authorization'] = 'Bearer $token'
          ..fields['message'] = message;
    if (pageUrl != null) request.fields['page_url'] = pageUrl;
    if (screenshot != null) {
      request.files.add(
        http.MultipartFile.fromBytes(
          'screenshots[]',
          screenshot,
          filename: screenshotName,
        ),
      );
    }
    final streamed = await _http.send(request);
    final response = await http.Response.fromStream(streamed);
    final payload = response.body.isEmpty ? null : jsonDecode(response.body);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      final map = payload is Map
          ? Map<String, dynamic>.from(payload)
          : const <String, dynamic>{};
      throw BeanApiException(
        (map['message'] ?? 'Could not submit issue.').toString(),
        statusCode: response.statusCode,
      );
    }
    return _map(
      payload is Map && payload['data'] is Map ? payload['data'] : payload,
    );
  }

  String workspacePath(String path, Object? workspaceId) {
    if (workspaceId == null) return path;
    final separator = path.contains('?') ? '&' : '?';
    return '$path${separator}workspace_id=${Uri.encodeQueryComponent(workspaceId.toString())}';
  }

  static Map<String, dynamic> _map(dynamic value) =>
      value is Map ? Map<String, dynamic>.from(value) : <String, dynamic>{};

  void close() => _http.close();
}
