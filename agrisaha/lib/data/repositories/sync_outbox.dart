import 'package:sqflite/sqflite.dart';

/// Enregistre les modifications locales à envoyer vers un futur serveur.
///
/// Aucun serveur n'est configuré dans cette version : la file sert à
/// préparer une synchronisation fiable (une seule opération par
/// enregistrement grâce à la contrainte UNIQUE, la dernière l'emporte).
class SyncOutbox {
  const SyncOutbox._();

  static Future<void> record(
    DatabaseExecutor db, {
    required String entity,
    required String entityId,
    required String operation,
    bool isDemo = false,
  }) async {
    // Les données de démonstration ne sont jamais synchronisées.
    if (isDemo) return;
    // « INSERT OR REPLACE » (et non UPSERT) reste compatible avec les
    // anciennes versions de SQLite embarquées dans Android < 11.
    await db.rawInsert(
      '''
      INSERT OR REPLACE INTO sync_outbox
        (entity, entity_id, operation, queued_at, attempts)
      VALUES (?, ?, ?, ?, 0)
      ''',
      [entity, entityId, operation, DateTime.now().toIso8601String()],
    );
  }

  static Future<int> pendingCount(DatabaseExecutor db) async {
    final result = await db.rawQuery('SELECT COUNT(*) AS c FROM sync_outbox');
    return (result.first['c'] as int?) ?? 0;
  }
}
