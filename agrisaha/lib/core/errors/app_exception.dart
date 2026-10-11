import 'package:flutter/foundation.dart';

/// Erreur métier dont le message est destiné à l'utilisateur.
class AppException implements Exception {
  const AppException(this.userMessage, {this.cause});

  final String userMessage;
  final Object? cause;

  @override
  String toString() => 'AppException: $userMessage';
}

/// Erreur levée lorsqu'une donnée attendue n'existe pas (ou plus).
class NotFoundException extends AppException {
  const NotFoundException(super.userMessage);
}

/// Erreur de validation d'une règle métier.
class ValidationException extends AppException {
  const ValidationException(super.userMessage);
}

/// Point central de traduction des erreurs en messages compréhensibles et
/// de journalisation (sans données personnelles : seul le type d'erreur est
/// consigné).
class ErrorHandler {
  const ErrorHandler._();

  static String messageFor(Object error) {
    if (error is AppException) return error.userMessage;
    return "Une erreur inattendue s'est produite. Vos données enregistrées "
        'ne sont pas perdues. Réessayez ou redémarrez l’application.';
  }

  static void log(Object error, [StackTrace? stackTrace]) {
    // On ne journalise que le type d'erreur pour ne pas exposer de données
    // saisies par l'agriculteur.
    debugPrint('[AgriSaha] ${error.runtimeType}');
    if (kDebugMode && stackTrace != null) {
      debugPrintStack(stackTrace: stackTrace, maxFrames: 8);
    }
  }
}
