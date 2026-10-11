/// Horloge injectable (facilite les tests sur les dates).
typedef Clock = DateTime Function();

/// Supprime les espaces superflus ; une chaîne vide devient `null`.
String? cleanText(String? value) {
  final trimmed = value?.trim();
  return trimmed == null || trimmed.isEmpty ? null : trimmed;
}
