import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

import 'migrations.dart';

/// Accès à la base SQLite locale (fonctionnement hors connexion).
class AppDatabase {
  AppDatabase._(this.db);

  final Database db;

  static const fileName = 'agrisaha.db';

  /// Ouvre (ou crée) la base. [factory] et [path] permettent d'utiliser une
  /// base en mémoire dans les tests (`sqflite_common_ffi`).
  static Future<AppDatabase> open({
    DatabaseFactory? factory,
    String? path,
  }) async {
    final dbFactory = factory ?? databaseFactory;
    final dbPath = path ?? p.join(await dbFactory.getDatabasesPath(), fileName);
    final db = await dbFactory.openDatabase(
      dbPath,
      options: OpenDatabaseOptions(
        version: schemaVersion,
        onConfigure: (db) async {
          await db.execute('PRAGMA foreign_keys = ON');
        },
        onCreate: (db, version) => _migrate(db, 0, version),
        onUpgrade: _migrate,
      ),
    );
    return AppDatabase._(db);
  }

  static Future<void> _migrate(Database db, int from, int to) async {
    await db.transaction((txn) async {
      for (var version = from + 1; version <= to; version++) {
        for (final statement in migrations[version - 1]) {
          await txn.execute(statement);
        }
      }
    });
  }

  Future<void> close() => db.close();
}
