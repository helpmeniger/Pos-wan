import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/date_utils.dart';
import '../../core/utils/id_generator.dart';
import '../models/enums.dart';
import '../models/journal_entry.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';

class JournalRepository {
  JournalRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  static const entity = 'journal_entries';

  /// Chronologie d'une culture, de la plus récente à la plus ancienne.
  Future<List<JournalEntry>> getForCrop(String cropId) async {
    final rows = await _database.db.query(
      'journal_entries',
      where: 'crop_id = ?',
      whereArgs: [cropId],
      orderBy: 'entry_date DESC, created_at DESC',
    );
    return rows.map(JournalEntry.fromMap).toList();
  }

  Future<List<JournalEntry>> getAll() async {
    final rows = await _database.db.query(
      'journal_entries',
      orderBy: 'entry_date DESC, created_at DESC',
    );
    return rows.map(JournalEntry.fromMap).toList();
  }

  Future<JournalEntry> add({
    required String cropId,
    required DateTime entryDate,
    required JournalEntryType type,
    required String title,
    String? details,
    double? quantity,
    String? quantityUnit,
    String? photoPath,
    bool isDemo = false,
  }) async {
    if (title.trim().isEmpty) {
      throw const ValidationException('Le titre est obligatoire.');
    }
    final now = _clock();
    if (DateOnly.of(entryDate).isAfter(DateOnly.today(now))) {
      throw const ValidationException(
        'La date d’une observation ne peut pas être dans le futur.',
      );
    }
    if (quantity != null && quantity < 0) {
      throw const ValidationException('La quantité ne peut pas être négative.');
    }
    final entry = JournalEntry(
      id: IdGenerator.newId(),
      cropId: cropId,
      entryDate: DateOnly.of(entryDate),
      type: type,
      title: title.trim(),
      details: cleanText(details),
      quantity: quantity,
      quantityUnit: quantity == null ? null : cleanText(quantityUnit),
      photoPath: photoPath,
      isDemo: isDemo,
      createdAt: now,
      updatedAt: now,
    );
    await _database.db.transaction((txn) async {
      final crop = await txn.query(
        'crops',
        where: 'id = ?',
        whereArgs: [cropId],
        limit: 1,
      );
      if (crop.isEmpty) {
        throw const NotFoundException('Cette culture n’existe plus.');
      }
      await txn.insert('journal_entries', entry.toMap());
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
        'journal_entries',
        columns: ['is_demo'],
        where: 'id = ?',
        whereArgs: [entryId],
      );
      if (rows.isEmpty) return;
      await txn.delete(
        'journal_entries',
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
