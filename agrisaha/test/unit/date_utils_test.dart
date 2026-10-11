import 'package:agrisaha/core/utils/date_utils.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('DateOnly.addDays', () {
    test('change de mois', () {
      expect(
        DateOnly.addDays(DateTime.utc(2025, 1, 30), 3),
        DateTime.utc(2025, 2, 2),
      );
      expect(
        DateOnly.addDays(DateTime.utc(2025, 4, 30), 1),
        DateTime.utc(2025, 5, 1),
      );
    });

    test('change d’année', () {
      expect(
        DateOnly.addDays(DateTime.utc(2025, 12, 15), 30),
        DateTime.utc(2026, 1, 14),
      );
      expect(
        DateOnly.addDays(DateTime.utc(2026, 1, 5), -10),
        DateTime.utc(2025, 12, 26),
      );
    });

    test('année bissextile : 29 février', () {
      expect(
        DateOnly.addDays(DateTime.utc(2024, 2, 28), 1),
        DateTime.utc(2024, 2, 29),
      );
      expect(
        DateOnly.addDays(DateTime.utc(2023, 2, 28), 1),
        DateTime.utc(2023, 3, 1),
      );
      expect(
        DateOnly.addDays(DateTime.utc(2024, 2, 1), 30),
        DateTime.utc(2024, 3, 2),
      );
      expect(
        DateOnly.addDays(DateTime.utc(2023, 2, 1), 30),
        DateTime.utc(2023, 3, 3),
      );
    });

    test('ignore l’heure de la date d’origine', () {
      expect(
        DateOnly.addDays(DateTime(2025, 6, 1, 23, 59), 1),
        DateTime.utc(2025, 6, 2),
      );
    });
  });

  group('DateOnly.daysBetween', () {
    test('compte les jours d’une année bissextile', () {
      expect(
        DateOnly.daysBetween(
          DateTime.utc(2024, 1, 1),
          DateTime.utc(2025, 1, 1),
        ),
        366,
      );
      expect(
        DateOnly.daysBetween(
          DateTime.utc(2025, 1, 1),
          DateTime.utc(2026, 1, 1),
        ),
        365,
      );
    });

    test('négatif si la date cible est antérieure', () {
      expect(
        DateOnly.daysBetween(
          DateTime.utc(2025, 3, 10),
          DateTime.utc(2025, 3, 1),
        ),
        -9,
      );
    });

    test('à travers fin février', () {
      expect(
        DateOnly.daysBetween(
          DateTime.utc(2024, 2, 20),
          DateTime.utc(2024, 3, 5),
        ),
        14,
      );
      expect(
        DateOnly.daysBetween(
          DateTime.utc(2023, 2, 20),
          DateTime.utc(2023, 3, 5),
        ),
        13,
      );
    });
  });

  group('isLeapYear', () {
    test('règles grégoriennes', () {
      expect(DateOnly.isLeapYear(2024), isTrue);
      expect(DateOnly.isLeapYear(2023), isFalse);
      expect(DateOnly.isLeapYear(2000), isTrue);
      expect(DateOnly.isLeapYear(1900), isFalse);
    });
  });

  group('format ISO', () {
    test('aller-retour', () {
      final date = DateTime.utc(2024, 2, 29);
      expect(DateOnly.toIso(date), '2024-02-29');
      expect(DateOnly.parse('2024-02-29'), date);
    });

    test('refuse une date inexistante', () {
      expect(() => DateOnly.parse('2023-02-29'), throwsFormatException);
      expect(() => DateOnly.parse('2025-13-01'), throwsFormatException);
      expect(() => DateOnly.parse('abc'), throwsFormatException);
      expect(DateOnly.tryParse('2025-04-31'), isNull);
    });
  });
}
