import '../../core/database/app_database.dart';
import '../../core/utils/date_utils.dart';
import '../../domain/harvest_estimator.dart';
import '../catalog/crop_catalog.dart';
import '../models/enums.dart';
import 'crop_repository.dart';
import 'finance_repository.dart';
import 'harvest_repository.dart';
import 'journal_repository.dart';
import 'plot_repository.dart';
import 'repository_utils.dart';
import 'sync_outbox.dart';

/// Données de démonstration (marquées `is_demo = 1`, jamais mélangées aux
/// données réelles ni synchronisées) et effacement des données.
class DataMaintenanceService {
  DataMaintenanceService(this._database, {Clock? clock})
    : _clock = clock ?? DateTime.now;

  final AppDatabase _database;
  final Clock _clock;

  static const _tablesWithDemo = [
    'finance_entries',
    'harvests',
    'journal_entries',
    'crop_tasks',
    'crops',
    'plots',
  ];

  Future<bool> hasDemoData() async {
    final rows = await _database.db.rawQuery(
      'SELECT COUNT(*) AS c FROM plots WHERE is_demo = 1',
    );
    return ((rows.first['c'] as int?) ?? 0) > 0;
  }

  Future<int> pendingSyncCount() => SyncOutbox.pendingCount(_database.db);

  /// Charge un petit jeu d'exemple clairement identifié « (démo) ».
  Future<void> loadDemoData() async {
    if (await hasDemoData()) return;
    final now = _clock();
    final today = DateOnly.today(now);
    final plots = PlotRepository(_database, clock: _clock);
    final crops = CropRepository(_database, clock: _clock);
    final journal = JournalRepository(_database, clock: _clock);
    final harvests = HarvestRepository(_database, clock: _clock);
    final finances = FinanceRepository(_database, clock: _clock);

    final field = await plots.create(
      name: 'Champ de case (démo)',
      area: 2,
      areaUnit: AreaUnit.hectare,
      region: 'Tillabéri',
      commune: 'Say',
      soilType: SoilType.sableux,
      isDemo: true,
    );
    final garden = await plots.create(
      name: 'Jardin maraîcher (démo)',
      area: 2500,
      areaUnit: AreaUnit.squareMeter,
      region: 'Tahoua',
      commune: 'Galmi',
      soilType: SoilType.argiloSableux,
      isDemo: true,
    );

    final milletSowing = DateOnly.addDays(today, -40);
    final millet = await crops.create(
      CropDraft(
        plotId: field.id,
        cropType: CropType.mil,
        varietyName: 'HKP',
        varietyRef: 'catalog:mil:hkp',
        seedType: SeedType.amelioree,
        area: 1.5,
        areaUnit: AreaUnit.hectare,
        region: field.region,
        commune: field.commune,
        soilType: field.soilType,
        irrigationMode: IrrigationMode.pluvial,
        estimate: HarvestEstimator.fromVariety(
          milletSowing,
          CropCatalog.varietyById('catalog:mil:hkp')!,
        ),
      ),
      isDemo: true,
    );
    await journal.add(
      cropId: millet.id,
      entryDate: DateOnly.addDays(today, -32),
      type: JournalEntryType.observation,
      title: 'Bonne levée (démo)',
      details: 'Quelques poquets manquants en bordure.',
      isDemo: true,
    );

    final onionSowing = DateOnly.addDays(today, -125);
    final onion = await crops.create(
      CropDraft(
        plotId: garden.id,
        cropType: CropType.oignon,
        varietyName: 'Violet de Galmi',
        seedType: SeedType.traditionnelle,
        area: 2000,
        areaUnit: AreaUnit.squareMeter,
        region: garden.region,
        commune: garden.commune,
        soilType: garden.soilType,
        irrigationMode: IrrigationMode.irrigation,
        estimate: HarvestEstimator.generic(onionSowing, CropType.oignon)!,
      ),
      isDemo: true,
    );
    final onionHarvest = await harvests.record(
      HarvestDraft(
        cropId: onion.id,
        harvestDate: DateOnly.addDays(today, -5),
        quantity: 40,
        unit: HarvestUnit.sac,
        kgPerUnit: 50,
        soldQuantity: 30,
        storedQuantity: 10,
        unitPrice: 12000,
        notes: 'Exemple de démonstration',
      ),
      isDemo: true,
    );
    await finances.add(
      category: FinanceCategory.semences,
      amount: 25000,
      entryDate: onionSowing,
      cropId: onion.id,
      plotId: garden.id,
      description: 'Semences (démo)',
      isDemo: true,
    );
    await finances.add(
      category: FinanceCategory.mainOeuvre,
      amount: 60000,
      entryDate: onionHarvest.harvestDate,
      cropId: onion.id,
      plotId: garden.id,
      description: 'Récolte (démo)',
      isDemo: true,
    );
  }

  /// Supprime uniquement les données de démonstration.
  Future<void> clearDemoData() async {
    await _database.db.transaction((txn) async {
      for (final table in _tablesWithDemo) {
        await txn.delete(table, where: 'is_demo = 1');
      }
    });
  }

  /// Efface toutes les données locales (action explicite de l'utilisateur).
  Future<void> deleteAllData() async {
    await _database.db.transaction((txn) async {
      for (final table in [
        ..._tablesWithDemo,
        'crop_varieties',
        'farm_profile',
        'sync_outbox',
        'app_settings',
      ]) {
        await txn.delete(table);
      }
    });
  }
}
