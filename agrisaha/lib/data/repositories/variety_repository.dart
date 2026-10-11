import 'package:sqflite/sqflite.dart';

import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/id_generator.dart';
import '../catalog/crop_catalog.dart';
import '../models/crop_variety.dart';
import '../models/enums.dart';
import 'repository_utils.dart';

/// Variétés disponibles : catalogue intégré + variétés ajoutées par
/// l'agriculteur ou le conseiller.
class VarietyRepository {
  VarietyRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  Future<List<CropVariety>> getForCrop(CropType cropType) async {
    final rows = await _database.db.query(
      'crop_varieties',
      where: 'crop_type = ?',
      whereArgs: [cropType.name],
      orderBy: 'name COLLATE NOCASE',
    );
    return [
      ...CropCatalog.varietiesFor(cropType),
      ...rows.map(CropVariety.fromMap),
    ];
  }

  Future<CropVariety?> getById(String id) async {
    final catalog = CropCatalog.varietyById(id);
    if (catalog != null) return catalog;
    final rows = await _database.db.query(
      'crop_varieties',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    return rows.isEmpty ? null : CropVariety.fromMap(rows.first);
  }

  Future<CropVariety> addUserVariety({
    required CropType cropType,
    required String name,
    required int cycleDays,
    String? source,
  }) async {
    final trimmed = name.trim();
    if (trimmed.isEmpty) {
      throw const ValidationException('Indiquez le nom de la variété.');
    }
    if (cycleDays < 20 || cycleDays > 400) {
      throw const ValidationException(
        'La durée du cycle doit être comprise entre 20 et 400 jours.',
      );
    }
    final variety = CropVariety(
      id: IdGenerator.newId(),
      cropType: cropType,
      name: trimmed,
      cycleMinDays: cycleDays,
      cycleMaxDays: cycleDays,
      typicalCycleDays: cycleDays,
      source: cleanText(source) ?? 'Saisie agriculteur / conseiller',
      validationStatus: ValidationStatus.userProvided,
      isUserDefined: true,
    );
    try {
      await _database.db.insert('crop_varieties', variety.toMap(_clock()));
    } on DatabaseException catch (e) {
      if (e.isUniqueConstraintError()) {
        throw const ValidationException(
          'Cette variété existe déjà pour cette culture.',
        );
      }
      rethrow;
    }
    return variety;
  }
}
