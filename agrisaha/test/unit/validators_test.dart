import 'package:agrisaha/core/utils/formatters.dart';
import 'package:agrisaha/core/validation/validators.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('texte obligatoire', () {
    expect(Validators.requiredText(''), isNotNull);
    expect(Validators.requiredText('   '), isNotNull);
    expect(Validators.requiredText('Champ'), isNull);
    expect(Validators.requiredText('x' * 200), isNotNull);
  });

  test('nombre positif (virgule ou point)', () {
    expect(Validators.positiveNumber(''), isNotNull);
    expect(Validators.positiveNumber('abc'), isNotNull);
    expect(Validators.positiveNumber('0'), isNotNull);
    expect(Validators.positiveNumber('-2'), isNotNull);
    expect(Validators.positiveNumber('1,5'), isNull);
    expect(Validators.positiveNumber('2.25'), isNull);
    expect(Formatters.parseDecimal('1 250,5'), 1250.5);
  });

  test('nombre facultatif', () {
    expect(Validators.optionalPositiveNumber(''), isNull);
    expect(Validators.optionalPositiveNumber('0'), isNull);
    expect(Validators.optionalPositiveNumber('0', allowZero: false), isNotNull);
    expect(Validators.optionalPositiveNumber('-1'), isNotNull);
  });

  test('durée de cycle', () {
    expect(Validators.cycleDays('90'), isNull);
    expect(Validators.cycleDays('5'), isNotNull);
    expect(Validators.cycleDays('9,5'), isNotNull);
    expect(Validators.cycleDays('900'), isNotNull);
  });

  test('date de semis', () {
    final today = DateTime.utc(2025, 7, 1);
    expect(Validators.sowingDate(null, today), isNotNull);
    expect(Validators.sowingDate(DateTime.utc(2025, 6, 1), today), isNull);
    expect(Validators.sowingDate(DateTime.utc(2025, 8, 1), today), isNull);
    expect(Validators.sowingDate(DateTime.utc(2022, 6, 1), today), isNotNull);
    expect(Validators.sowingDate(DateTime.utc(2027, 6, 1), today), isNotNull);
  });

  test('date réelle de récolte', () {
    final sowing = DateTime.utc(2025, 6, 1);
    final today = DateTime.utc(2025, 9, 1);
    expect(
      Validators.harvestDate(DateTime.utc(2025, 8, 30), sowing, today),
      isNull,
    );
    expect(
      Validators.harvestDate(DateTime.utc(2025, 6, 1), sowing, today),
      isNotNull,
    );
    expect(
      Validators.harvestDate(DateTime.utc(2025, 9, 2), sowing, today),
      isNotNull,
    );
  });
}
