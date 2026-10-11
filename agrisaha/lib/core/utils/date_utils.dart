/// Calculs de dates « calendaires » (sans heure) fiables.
///
/// Toutes les dates agricoles sont manipulées en UTC à minuit afin d'éviter
/// les décalages liés aux changements d'heure éventuels du téléphone. Les
/// additions de jours passent par le constructeur `DateTime.utc` qui gère
/// automatiquement les fins de mois et les années bissextiles.
class DateOnly {
  const DateOnly._();

  /// Retourne la date (année, mois, jour) sans composante horaire.
  static DateTime of(DateTime date) =>
      DateTime.utc(date.year, date.month, date.day);

  /// Date du jour, éventuellement à partir d'une horloge injectée.
  static DateTime today([DateTime? now]) => of(now ?? DateTime.now());

  /// Ajoute (ou retire si négatif) un nombre de jours calendaires.
  static DateTime addDays(DateTime date, int days) =>
      DateTime.utc(date.year, date.month, date.day + days);

  /// Nombre de jours calendaires entre [from] et [to] (négatif si [to] est
  /// antérieur à [from]).
  static int daysBetween(DateTime from, DateTime to) =>
      of(to).difference(of(from)).inDays;

  /// Format de stockage ISO 8601 : `yyyy-MM-dd`.
  static String toIso(DateTime date) {
    final d = of(date);
    final month = d.month.toString().padLeft(2, '0');
    final day = d.day.toString().padLeft(2, '0');
    return '${d.year.toString().padLeft(4, '0')}-$month-$day';
  }

  /// Analyse une date stockée au format `yyyy-MM-dd`.
  ///
  /// Lève une [FormatException] si la chaîne n'est pas une date valide
  /// (par exemple `2023-02-29`).
  static DateTime parse(String value) {
    final match = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(value);
    if (match == null) {
      throw FormatException('Date invalide', value);
    }
    final year = int.parse(match.group(1)!);
    final month = int.parse(match.group(2)!);
    final day = int.parse(match.group(3)!);
    final result = DateTime.utc(year, month, day);
    if (result.year != year || result.month != month || result.day != day) {
      throw FormatException('Date inexistante', value);
    }
    return result;
  }

  static DateTime? tryParse(String? value) {
    if (value == null || value.isEmpty) return null;
    try {
      return parse(value);
    } on FormatException {
      return null;
    }
  }

  static bool isLeapYear(int year) =>
      (year % 4 == 0 && year % 100 != 0) || year % 400 == 0;
}
