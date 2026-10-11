import 'package:agrisaha/core/database/app_database.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Ouvre une base SQLite en mémoire (implémentation FFI sans isolate pour
/// fonctionner aussi dans les tests de widgets).
Future<AppDatabase> openTestDatabase({String? path}) {
  sqfliteFfiInit();
  return AppDatabase.open(
    factory: databaseFactoryFfiNoIsolate,
    path: path ?? inMemoryDatabasePath,
  );
}

/// Horloge fixe pour des tests reproductibles.
DateTime Function() fixedClock(DateTime value) =>
    () => value;
