import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/id_generator.dart';
import '../models/enums.dart';
import '../models/finance_entry.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';

class FinanceRepository {
  FinanceRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  static const entity = 'finance_entries';

  Future<List<FinanceEntry>> getAll() async {
    final rows = await _database.db.query(
      'finance_entries',
      orderBy: 'entry_date DESC, created_at DESC',
    );
    return rows.map(FinanceEntry.fromMap).toList();
  }

  Future<FinanceEntry> add({
    required FinanceCategory category,
    required double amount,
    required DateTime entryDate,
    String? cropId,
    String? plotId,
    String? description,
    bool isDemo = false,
  }) async {
    if (amount.isNaN || amount <= 0) {
      throw const ValidationException('Le montant doit être supérieur à zéro.');
    }
    final now = _clock();
    if (DateOnly.of(entryDate).isAfter(DateOnly.today(now))) {
      throw const ValidationException(
        'La date ne peut pas être dans le futur.',
      );
    }
    final entry = FinanceEntry(
      id: IdGenerator.newId(),
      category: category,
      amount: amount,
      entryDate: DateOnly.of(entryDate),
      cropId: cropId,
      plotId: plotId,
      description: cleanText(description),
      isDemo: isDemo,
      createdAt: now,
      updatedAt: now,
    );
    await _database.db.transaction((txn) async {
      await txn.insert('finance_entries', entry.toMap());
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: entry.id,
        operation: 'upsert',
        isDemo: isDemo,
      );
    });
    return entry;
  }

  Future<void> delete(String entryId) async {
    await _database.db.transaction((txn) async {
      final rows = await txn.query(
        'finance_entries',
        columns: ['is_demo'],
        where: 'id = ?',
        whereArgs: [entryId],
      );
      if (rows.isEmpty) return;
      await txn.delete(
        'finance_entries',
        where: 'id = ?',
        whereArgs: [entryId],
      );
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: entryId,
        operation: 'delete',
        isDemo: rows.first['is_demo'] == 1,
      );
    });
  }
}
