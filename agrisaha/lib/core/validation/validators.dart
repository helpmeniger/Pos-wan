import '../utils/date_utils.dart';
import '../utils/formatters.dart';

/// Validateurs de formulaires. Retournent `null` si la valeur est valide,
/// sinon un message compréhensible.
class Validators {
  const Validators._();

  static const int maxTextLength = 120;
  static const int maxNotesLength = 2000;

  static String? requiredText(String? value, {String field = 'Ce champ'}) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return '$field est obligatoire.';
    if (text.length > maxTextLength) {
      return '$field ne doit pas dépasser $maxTextLength caractères.';
    }
    return null;
  }

  static String? optionalText(String? value, {int max = maxTextLength}) {
    if ((value?.trim().length ?? 0) > max) {
      return 'Texte trop long ($max caractères maximum).';
    }
    return null;
  }

  static String? positiveNumber(String? value, {String field = 'La valeur'}) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return '$field est obligatoire.';
    final number = Formatters.parseDecimal(text);
    if (number == null) return 'Saisissez un nombre valide (ex. 1,5).';
    if (number <= 0) return '$field doit être supérieure à zéro.';
    if (number > 1e9) return '$field est trop grande.';
    return null;
  }

  static String? optionalPositiveNumber(
    String? value, {
    bool allowZero = true,
  }) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return null;
    final number = Formatters.parseDecimal(text);
    if (number == null) return 'Saisissez un nombre valide (ex. 1,5).';
    if (number < 0 || (!allowZero && number == 0)) {
      return allowZero
          ? 'La valeur ne peut pas être négative.'
          : 'La valeur doit être supérieure à zéro.';
    }
    if (number > 1e9) return 'La valeur est trop grande.';
    return null;
  }

  static String? cycleDays(String? value) {
    final text = value?.trim() ?? '';
    if (text.isEmpty) return 'Indiquez la durée du cycle en jours.';
    final days = int.tryParse(text);
    if (days == null) return 'Saisissez un nombre entier de jours.';
    if (days < 20 || days > 400) {
      return 'La durée doit être comprise entre 20 et 400 jours.';
    }
    return null;
  }

  /// Date de semis : jusqu'à 2 ans dans le passé (saisie d'historique) et
  /// 1 an dans le futur (planification).
  static String? sowingDate(DateTime? date, DateTime today) {
    if (date == null) return 'Choisissez la date de semis.';
    final diff = DateOnly.daysBetween(today, date);
    if (diff < -730) return 'La date de semis est trop ancienne (2 ans max).';
    if (diff > 365) return 'La date de semis est trop lointaine (1 an max).';
    return null;
  }

  static String? harvestDate(
    DateTime? harvest,
    DateTime sowing,
    DateTime today,
  ) {
    if (harvest == null) return 'Choisissez la date de récolte.';
    if (!DateOnly.of(harvest).isAfter(DateOnly.of(sowing))) {
      return 'La récolte doit être postérieure au semis.';
    }
    if (DateOnly.of(harvest).isAfter(DateOnly.of(today))) {
      return 'La date réelle de récolte ne peut pas être dans le futur.';
    }
    return null;
  }
}
