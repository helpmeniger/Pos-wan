import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/id_generator.dart';
import '../models/crop.dart';
import '../models/enums.dart';
import '../models/finance_entry.dart';
import '../models/harvest.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';
import 'task_repository.dart';

/// Saisie d'une récolte réelle.
class HarvestDraft {
  const HarvestDraft({
    required this.cropId,
    required this.harvestDate,
    required this.quantity,
    required this.unit,
    this.unitLabel,
    this.kgPerUnit,
    this.harvestedArea,
    this.harvestedAreaUnit,
    this.lossesQuantity,
    this.soldQuantity,
    this.storedQuantity,
    this.unitPrice,
    this.isFinal = true,
    this.notes,
  });

  final String cropId;
  final DateTime harvestDate;
  final double quantity;
  final HarvestUnit unit;
  final String? unitLabel;
  final double? kgPerUnit;
  final double? harvestedArea;
  final AreaUnit? harvestedAreaUnit;
  final double? lossesQuantity;
  final double? soldQuantity;
  final double? storedQuantity;
  final double? unitPrice;
  final bool isFinal;
  final String? notes;
}

class HarvestRepository {
  HarvestRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  static const entity = 'harvests';

  Future<List<Harvest>> getForCrop(String cropId) async {
    final rows = await _database.db.query(
      'harvests',
      where: 'crop_id = ?',
      whereArgs: [cropId],
      orderBy: 'harvest_date ASC',
    );
    return rows.map(Harvest.fromMap).toList();
  }

  Future<List<Harvest>> getAll() async {
    final rows = await _database.db.query(
      'harvests',
      orderBy: 'harvest_date DESC',
    );
    return rows.map(Harvest.fromMap).toList();
  }

  /// Enregistre la récolte. Si [recordSaleAsIncome] est vrai et qu'une
  /// vente est déclarée, une recette liée est créée dans les finances.
  /// Une récolte finale fait passer la culture au statut « Récoltée ».
  Future<Harvest> record(
    HarvestDraft draft, {
    bool recordSaleAsIncome = true,
    bool isDemo = false,
  }) async {
    final now = _clock();
    late Harvest harvest;
    await _database.db.transaction((txn) async {
      final rows = await txn.query(
        'crops',
        where: 'id = ?',
        whereArgs: [draft.cropId],
        limit: 1,
      );
      if (rows.isEmpty) {
        throw const NotFoundException('Cette culture n’existe plus.');
      }
      final crop = Crop.fromMap(rows.first);
      validate(draft, crop, DateOnly.today(now));

      harvest = Harvest(
        id: IdGenerator.newId(),
        cropId: crop.id,
        harvestDate: DateOnly.of(draft.harvestDate),
        quantity: draft.quantity,
        unit: draft.unit,
        unitLabel: cleanText(draft.unitLabel),
        kgPerUnit:
            draft.unit == HarvestUnit.kg || draft.unit == HarvestUnit.tonne
            ? null
            : draft.kgPerUnit,
        harvestedArea: draft.harvestedArea,
        harvestedAreaUnit: draft.harvestedArea == null
            ? null
            : draft.harvestedAreaUnit,
        lossesQuantity: draft.lossesQuantity,
        soldQuantity: draft.soldQuantity,
        storedQuantity: draft.storedQuantity,
        unitPrice: draft.unitPrice,
        isFinal: draft.isFinal,
        estimatedHarvestDate: crop.estimatedHarvestDate,
        estimatedMaturationDays: crop.maturationDays,
        notes: cleanText(draft.notes),
        isDemo: isDemo,
        createdAt: now,
        updatedAt: now,
      );
      await txn.insert('harvests', harvest.toMap());
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: harvest.id,
        operation: 'upsert',
        isDemo: isDemo,
      );

      final revenue = harvest.salesRevenue;
      if (recordSaleAsIncome && revenue != null && revenue > 0) {
        final income = FinanceEntry(
          id: IdGenerator.newId(),
          category: FinanceCategory.vente,
          amount: revenue,
          entryDate: harvest.harvestDate,
          cropId: crop.id,
          plotId: crop.plotId,
          harvestId: harvest.id,
          description: 'Vente de ${crop.displayName}',
          isDemo: isDemo,
          createdAt: now,
          updatedAt: now,
        );
        await txn.insert('finance_entries', income.toMap());
        await SyncOutbox.record(
          txn,
          entity: 'finance_entries',
          entityId: income.id,
          operation: 'upsert',
          isDemo: isDemo,
        );
      }

      if (draft.isFinal) {
        await txn.update(
          'crops',
          {
            'status': CropStatus.harvested.name,
            'updated_at': now.toIso8601String(),
          },
          where: 'id = ?',
          whereArgs: [crop.id],
        );
        await TaskRepository.completeHarvestTasks(txn, crop.id, now);
      }
    });
    return harvest;
  }

  /// Supprime une récolte (et la recette liée). La culture repasse « En
  /// cours » s'il ne reste aucune récolte finale.
  Future<void> delete(String harvestId) async {
    await _database.db.transaction((txn) async {
      final rows = await txn.query(
        'harvests',
        where: 'id = ?',
        whereArgs: [harvestId],
        limit: 1,
      );
      if (rows.isEmpty) return;
      final harvest = Harvest.fromMap(rows.first);
      await txn.delete('harvests', where: 'id = ?', whereArgs: [harvestId]);
      final remaining = await txn.rawQuery(
        'SELECT COUNT(*) AS c FROM harvests WHERE crop_id = ? AND is_final = 1',
        [harvest.cropId],
      );
      if (((remaining.first['c'] as int?) ?? 0) == 0) {
        await txn.update(
          'crops',
          {
            'status': CropStatus.active.name,
            'updated_at': _clock().toIso8601String(),
          },
          where: 'id = ? AND status = ?',
          whereArgs: [harvest.cropId, CropStatus.harvested.name],
        );
      }
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: harvestId,
        operation: 'delete',
        isDemo: harvest.isDemo,
      );
    });
  }

  /// Règles métier de cohérence d'une récolte (appliquées aussi hors UI).
  static void validate(HarvestDraft draft, Crop crop, DateTime today) {
    if (draft.quantity.isNaN || draft.quantity < 0) {
      throw const ValidationException(
        'La quantité récoltée ne peut pas être négative.',
      );
    }
    if (!DateOnly.of(draft.harvestDate).isAfter(crop.sowingDate)) {
      throw const ValidationException(
        'La date de récolte doit être postérieure à la date de semis.',
      );
    }
    if (DateOnly.of(draft.harvestDate).isAfter(today)) {
      throw const ValidationException(
        'La date réelle de récolte ne peut pas être dans le futur.',
      );
    }
    final sold = draft.soldQuantity ?? 0;
    final stored = draft.storedQuantity ?? 0;
    if (sold + stored > draft.quantity + 1e-9) {
      throw const ValidationException(
        'Quantité vendue + conservée dépasse la quantité récoltée.',
      );
    }
    if ((draft.unit == HarvestUnit.autre) &&
        (draft.unitLabel?.trim().isEmpty ?? true)) {
      throw const ValidationException('Indiquez le nom de l’unité utilisée.');
    }
  }
}
