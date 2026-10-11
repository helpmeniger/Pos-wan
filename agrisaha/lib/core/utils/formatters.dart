import 'package:intl/intl.dart';

/// Formatage homogène des dates, nombres et montants affichés.
class Formatters {
  const Formatters._();

  static const locale = 'fr_FR';

  static String date(DateTime date) =>
      DateFormat('d MMM yyyy', locale).format(date);

  static String longDate(DateTime date) =>
      DateFormat('EEEE d MMMM yyyy', locale).format(date);

  static String shortDate(DateTime date) =>
      DateFormat('d MMM', locale).format(date);

  static String number(num value, {int maxDecimals = 2}) {
    final format = NumberFormat.decimalPattern(locale)
      ..maximumFractionDigits = maxDecimals;
    return format.format(value);
  }

  /// Montant en francs CFA (XOF), sans décimales.
  static String money(num value) {
    final format = NumberFormat.decimalPattern(locale)
      ..maximumFractionDigits = 0;
    return '${format.format(value)} FCFA';
  }

  static String days(int value) {
    final abs = value.abs();
    return '$abs jour${abs > 1 ? 's' : ''}';
  }

  /// Convertit une saisie utilisateur (« 1,5 » ou « 1.5 ») en nombre.
  static double? parseDecimal(String? input) {
    if (input == null) return null;
    final normalized = input.replaceAll(RegExp(r'\s'), '').replaceAll(',', '.');
    if (normalized.isEmpty) return null;
    return double.tryParse(normalized);
  }
}
