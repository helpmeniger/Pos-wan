import '../../core/database/app_database.dart';
import '../../core/errors/app_exception.dart';
import '../../core/utils/id_generator.dart';
import '../models/enums.dart';
import '../models/plot.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';

class PlotRepository {
  PlotRepository(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  static const entity = 'plots';

  Future<List<Plot>> getAll() async {
    final rows = await _database.db.query(
      'plots',
      orderBy: 'name COLLATE NOCASE ASC',
    );
    return rows.map(Plot.fromMap).toList();
  }

  Future<Plot?> getById(String id) async {
    final rows = await _database.db.query(
      'plots',
      where: 'id = ?',
      whereArgs: [id],
      limit: 1,
    );
    return rows.isEmpty ? null : Plot.fromMap(rows.first);
  }

  Future<Plot> create({
    required String name,
    required double area,
    required AreaUnit areaUnit,
    String? region,
    String? commune,
    String? village,
    SoilType soilType = SoilType.inconnu,
    String? notes,
    bool isDemo = false,
  }) async {
    _validate(name, area);
    final now = _clock();
    final plot = Plot(
      id: IdGenerator.newId(),
      name: name.trim(),
      area: area,
      areaUnit: areaUnit,
      region: cleanText(region),
      commune: cleanText(commune),
      village: cleanText(village),
      soilType: soilType,
      notes: cleanText(notes),
      isDemo: isDemo,
      createdAt: now,
      updatedAt: now,
    );
    await _database.db.transaction((txn) async {
      await txn.insert('plots', plot.toMap());
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: plot.id,
        operation: 'upsert',
        isDemo: isDemo,
      );
    });
    return plot;
  }

  Future<Plot> update(Plot plot) async {
    _validate(plot.name, plot.area);
    final updated = plot.copyWith(updatedAt: _clock());
    await _database.db.transaction((txn) async {
      final count = await txn.update(
        'plots',
        updated.toMap(),
        where: 'id = ?',
        whereArgs: [plot.id],
      );
      if (count == 0) {
        throw const NotFoundException('Cette parcelle n’existe plus.');
      }
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: plot.id,
        operation: 'upsert',
        isDemo: plot.isDemo,
      );
    });
    return updated;
  }

  /// Supprime une parcelle sans culture. Les cultures doivent d'abord être
  /// supprimées pour éviter toute perte involontaire d'historique.
  Future<void> delete(String id) async {
    await _database.db.transaction((txn) async {
      final crops = await txn.rawQuery(
        'SELECT COUNT(*) AS c FROM crops WHERE plot_id = ?',
        [id],
      );
      if (((crops.first['c'] as int?) ?? 0) > 0) {
        throw const ValidationException(
          'Cette parcelle contient des cultures. Supprimez-les d’abord '
          'pour ne pas perdre leur historique par erreur.',
        );
      }
      final rows = await txn.query(
        'plots',
        columns: ['is_demo'],
        where: 'id = ?',
        whereArgs: [id],
      );
      await txn.delete('plots', where: 'id = ?', whereArgs: [id]);
      await SyncOutbox.record(
        txn,
        entity: entity,
        entityId: id,
        operation: 'delete',
        isDemo: rows.isNotEmpty && rows.first['is_demo'] == 1,
      );
    });
  }

  static void _validate(String name, double area) {
    if (name.trim().isEmpty) {
      throw const ValidationException('Le nom de la parcelle est obligatoire.');
    }
    if (area.isNaN || area <= 0) {
      throw const ValidationException(
        'La superficie doit être supérieure à zéro.',
      );
    }
  }
}
