import 'package:sqflite/sqflite.dart';

import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/id_generator.dart';
import '../../domain/calendar_generator.dart';
import '../../domain/harvest_estimator.dart';
import '../../domain/yield_calculator.dart';
import '../models/crop.dart';
import '../models/crop_task.dart';
import '../models/enums.dart';
import '../models/plot.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';

/// Données saisies dans le formulaire d'enregistrement d'une culture.
class CropDraft {
  const CropDraft({
    required this.plotId,
    required this.cropType,
    this.customCropName,
    this.varietyName,
    this.varietyRef,
    required this.seedType,
    required this.area,
    required this.areaUnit,
    this.region,
    this.commune,
    this.village,
    this.soilType = SoilType.inconnu,
    required this.irrigationMode,
    required this.estimate,
    this.notes,
    this.photoPath,
  });

  final String plotId;
  final CropType cropType;
  final String? customCropName;
  final String? varietyName;
  final String? varietyRef;
  final SeedType seedType;
  final double area;
  final AreaUnit areaUnit;
  final String? region;
  final String? commune;
  final String? village;
  final SoilType soilType;
  final IrrigationMode irrigationMode;

  /// Estimation de récolte (contient la date de semis).
  final HarvestEstimate estimate;
  final String? notes;
  final String? photoPath;
}

class CropRepository {
  CropRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  static const entity = 'crops';

  Future<List<Crop>> getAll() async {
    final rows = await _database.db.query(
      'crops',
      orderBy: 'sowing_date DESC, created_at DESC',
    );
    return rows.map(Crop.fromMap).toList();
  }

  Future<Crop?> getById(String id) async {
    final rows = await _database.db.query(
      'crops',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    return rows.isEmpty ? null : Crop.fromMap(rows.first);
  }

  Future<List<Crop>> getByPlot(String plotId) async {
    final rows = await _database.db.query(
      'crops',
      where: 'plot_id = ?',
      whereArgs: [plotId],
      orderBy: 'sowing_date DESC',
    );
    return rows.map(Crop.fromMap).toList();
  }

  /// Enregistre la culture et génère son calendrier en une seule
  /// transaction (aucune culture sans calendrier en cas d'erreur).
  Future<Crop> create(
    CropDraft draft, {
    AreaConverter areaConverter = const AreaConverter(),
    bool isDemo = false,
  }) async {
    if (draft.area.isNaN || draft.area <= 0) {
      throw const ValidationException(
        'La superficie cultivée doit être supérieure à zéro.',
      );
    }
    if (draft.cropType == CropType.autre &&
        (draft.customCropName?.trim().isEmpty ?? true)) {
      throw const ValidationException('Indiquez le nom de la culture.');
    }
    final estimate = draft.estimate;
    if (estimate.maturationDays <= 0 ||
        !estimate.estimatedDate.isAfter(estimate.sowingDate)) {
      throw const ValidationException(
        'La date de récolte estimée doit suivre la date de semis.',
      );
    }

    final now = _clock();
    final crop = Crop(
      id: IdGenerator.newId(),
      plotId: draft.plotId,
      cropType: draft.cropType,
      customCropName: cleanText(draft.customCropName),
      varietyName: cleanText(draft.varietyName),
      varietyRef: draft.varietyRef,
      seedType: draft.seedType,
      sowingDate: estimate.sowingDate,
      area: draft.area,
      areaUnit: draft.areaUnit,
      region: cleanText(draft.region),
      commune: cleanText(draft.commune),
      village: cleanText(draft.village),
      soilType: draft.soilType,
      irrigationMode: draft.irrigationMode,
      maturationDays: estimate.maturationDays,
      maturationMinDays: estimate.minDays,
      maturationMaxDays: estimate.maxDays,
      estimateSource: estimate.source,
      estimatedHarvestDate: estimate.estimatedDate,
      notes: cleanText(draft.notes),
      photoPath: draft.photoPath,
      isDemo: isDemo,
      createdAt: now,
      updatedAt: now,
    );
    final tasks = CalendarGenerator.generate(
      cropId: crop.id,
      cropType: crop.cropType,
      irrigationMode: crop.irrigationMode,
      sowingDate: crop.sowingDate,
      estimatedHarvestDate: crop.estimatedHarvestDate,
      now: now,
      isDemo: isDemo,
    );

    await _database.db.transaction((txn) async {
      final plotRows = await txn.query(
        'plots',
        where: 'id = ?',
        whereArgs: [draft.plotId],
        limit: 1,
      );
      if (plotRows.isEmpty) {
        throw const NotFoundException('La parcelle choisie n’existe plus.');
      }
      _checkAreaFitsPlot(Plot.fromMap(plotRows.first), draft, areaConverter);
      await txn.insert('crops', crop.toMap());
      final batch = txn.batch();
      for (final task in tasks) {
        batch.insert('crop_tasks', task.toMap());
      }
      await batch.commit(noResult: true);
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: crop.id,
        operation: 'upsert',
        isDemo: isDemo,
      );
    });
    return crop;
  }

  /// Corrige la date estimée de récolte (agriculteur ou conseiller) et
  /// décale les tâches de fin de cycle encore à faire.
  Future<Crop> correctHarvestDate(String cropId, DateTime newDate) async {
    final now = _clock();
    late Crop updated;
    await _database.db.transaction((txn) async {
      final crop = await _getOrThrow(txn, cropId);
      final estimate = HarvestEstimator.manual(crop.sowingDate, newDate);
      updated = crop.copyWith(
        estimatedHarvestDate: estimate.estimatedDate,
        maturationDays: estimate.maturationDays,
        estimateSource: EstimateSource.manual,
        updatedAt: now,
      );
      await txn.update(
        'crops',
        updated.toMap(),
        where: 'id = ?',
        whereArgs: [cropId],
      );
      final taskRows = await txn.query(
        'crop_tasks',
        where: 'crop_id = ?',
        whereArgs: [cropId],
      );
      final shifted = CalendarGenerator.shiftHarvestAnchored(
        taskRows.map(CropTask.fromMap).toList(),
        estimate.estimatedDate,
        now,
      );
      for (final task in shifted) {
        await txn.update(
          'crop_tasks',
          task.toMap(),
          where: 'id = ?',
          whereArgs: [task.id],
        );
      }
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: cropId,
        operation: 'upsert',
        isDemo: crop.isDemo,
      );
    });
    return updated;
  }

  Future<void> setStatus(String cropId, CropStatus status) async {
    await _database.db.transaction((txn) async {
      final crop = await _getOrThrow(txn, cropId);
      await txn.update(
        'crops',
        {'status': status.name, 'updated_at': _clock().toIso8601String()},
        where: 'id = ?',
        whereArgs: [cropId],
      );
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: cropId,
        operation: 'upsert',
        isDemo: crop.isDemo,
      );
    });
  }

  Future<void> updatePhoto(String cropId, String? photoPath) async {
    await _database.db.update(
      'crops',
      {'photo_path': photoPath, 'updated_at': _clock().toIso8601String()},
      where: 'id = ?',
      whereArgs: [cropId],
    );
  }

  /// Supprime la culture ainsi que son calendrier, son journal et ses
  /// récoltes (suppression en cascade).
  Future<void> delete(String cropId) async {
    await _database.db.transaction((txn) async {
      final crop = await _getOrThrow(txn, cropId);
      await txn.delete('crops', where: 'id = ?', whereArgs: [cropId]);
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: cropId,
        operation: 'delete',
        isDemo: crop.isDemo,
      );
    });
  }

  static Future<Crop> _getOrThrow(DatabaseExecutor db, String cropId) async {
    final rows = await db.query(
      'crops',
      where: 'id = ?',
      whereArgs: [cropId],
      limit: 1,
    );
    if (rows.isEmpty) {
      throw const NotFoundException('Cette culture n’existe plus.');
    }
    return Crop.fromMap(rows.first);
  }

  static void _checkAreaFitsPlot(
    Plot plot,
    CropDraft draft,
    AreaConverter converter,
  ) {
    final plotHa = converter.toHectares(plot.area, plot.areaUnit);
    final cropHa = converter.toHectares(draft.area, draft.areaUnit);
    if (plotHa == null || cropHa == null) return;
    // Tolérance pour les arrondis de conversion.
    if (cropHa > plotHa * 1.0001) {
      throw ValidationException(
        'La superficie cultivée dépasse celle de la parcelle '
        '« ${plot.name} ».',
      );
    }
  }

  /// Statistiques des cycles réellement observés (semis → récolte finale)
  /// pour une culture/variété, afin d'améliorer les futures estimations.
  Future<ObservedCycle?> observedCycle(
    CropType cropType,
    String? varietyName,
  ) async {
    final args = <Object?>[cropType.name];
    var varietyClause = '';
    final variety = cleanText(varietyName);
    if (variety != null) {
      varietyClause = 'AND LOWER(c.variety_name) = LOWER(?)';
      args.add(variety);
    }
    final rows = await _database.db.rawQuery('''
      SELECT c.sowing_date AS sowing, h.harvest_date AS harvest
      FROM harvests h JOIN crops c ON c.id = h.crop_id
      WHERE h.is_final = 1 AND c.crop_type = ? $varietyClause
    ''', args);
    if (rows.isEmpty) return null;
    final cycles = [
      for (final row in rows)
        DateOnly.daysBetween(
          DateOnly.parse(row['sowing']! as String),
          DateOnly.parse(row['harvest']! as String),
        ),
    ];
    final avg = cycles.reduce((a, b) => a + b) / cycles.length;
    return ObservedCycle(averageDays: avg.round(), sampleSize: cycles.length);
  }
}

class ObservedCycle {
  const ObservedCycle({required this.averageDays, required this.sampleSize});

  final int averageDays;
  final int sampleSize;
}
