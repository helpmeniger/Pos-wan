import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/models/crop.dart';
import '../../data/models/crop_task.dart';
import '../../data/models/farm_profile.dart';
import '../../data/models/finance_entry.dart';
import '../../data/models/harvest.dart';
import '../../data/models/journal_entry.dart';
import '../../data/models/plot.dart';
import '../../data/repositories/crop_repository.dart';
import '../../data/repositories/data_maintenance_service.dart';
import '../../data/repositories/finance_repository.dart';
import '../../data/repositories/harvest_repository.dart';
import '../../data/repositories/journal_repository.dart';
import '../../data/repositories/plot_repository.dart';
import '../../data/repositories/profile_repository.dart';
import '../../data/repositories/repository_utils.dart';
import '../../data/repositories/task_repository.dart';
import '../../data/repositories/variety_repository.dart';
import '../../domain/alert_builder.dart';
import '../../domain/dashboard_calculator.dart';
import '../../domain/yield_calculator.dart';
import '../database/app_database.dart';
import '../utils/date_utils.dart';

/// Base locale, fournie au démarrage (voir `main.dart`) ou par les tests.
final databaseProvider = Provider<AppDatabase>(
  (ref) => throw UnimplementedError('databaseProvider doit être surchargé'),
);

/// Horloge injectable.
final clockProvider = Provider<Clock>((ref) => DateTime.now);

final todayProvider = Provider<DateTime>(
  (ref) => DateOnly.today(ref.watch(clockProvider)()),
);

/// Compteur incrémenté après chaque écriture : toutes les lectures en
/// dépendent et sont donc recalculées automatiquement.
class DataRevision extends Notifier<int> {
  @override
  int build() => 0;

  void bump() => state++;
}

final dataRevisionProvider = NotifierProvider<DataRevision, int>(
  DataRevision.new,
);

// ---------------------------------------------------------------------------
// Dépôts
// ---------------------------------------------------------------------------

final plotRepositoryProvider = Provider(
  (ref) => PlotRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final cropRepositoryProvider = Provider(
  (ref) => CropRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final taskRepositoryProvider = Provider(
  (ref) => TaskRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final journalRepositoryProvider = Provider(
  (ref) => JournalRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final harvestRepositoryProvider = Provider(
  (ref) => HarvestRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final financeRepositoryProvider = Provider(
  (ref) => FinanceRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final profileRepositoryProvider = Provider(
  (ref) => ProfileRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final varietyRepositoryProvider = Provider(
  (ref) => VarietyRepository(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);
final dataMaintenanceProvider = Provider(
  (ref) => DataMaintenanceService(
    ref.watch(databaseProvider),
    clock: ref.watch(clockProvider),
  ),
);

// ---------------------------------------------------------------------------
// Lectures
// ---------------------------------------------------------------------------

final profileProvider = FutureProvider<FarmProfile?>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(profileRepositoryProvider).get();
});

final areaConverterProvider = FutureProvider<AreaConverter>((ref) async {
  final profile = await ref.watch(profileProvider.future);
  return AreaConverter(localUnitHectares: profile?.localAreaUnitHectares);
});

final plotsProvider = FutureProvider<List<Plot>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(plotRepositoryProvider).getAll();
});

final cropsProvider = FutureProvider<List<Crop>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(cropRepositoryProvider).getAll();
});

final allTasksProvider = FutureProvider<List<CropTask>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(taskRepositoryProvider).getAll();
});

final allHarvestsProvider = FutureProvider<List<Harvest>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(harvestRepositoryProvider).getAll();
});

final financeEntriesProvider = FutureProvider<List<FinanceEntry>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(financeRepositoryProvider).getAll();
});

final allJournalProvider = FutureProvider<List<JournalEntry>>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(journalRepositoryProvider).getAll();
});

final cropProvider = FutureProvider.family<Crop?, String>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(cropRepositoryProvider).getById(id);
});

final plotProvider = FutureProvider.family<Plot?, String>((ref, id) {
  ref.watch(dataRevisionProvider);
  return ref.watch(plotRepositoryProvider).getById(id);
});

final cropTasksProvider = FutureProvider.family<List<CropTask>, String>((
  ref,
  cropId,
) {
  ref.watch(dataRevisionProvider);
  return ref.watch(taskRepositoryProvider).getForCrop(cropId);
});

final cropJournalProvider = FutureProvider.family<List<JournalEntry>, String>((
  ref,
  cropId,
) {
  ref.watch(dataRevisionProvider);
  return ref.watch(journalRepositoryProvider).getForCrop(cropId);
});

final cropHarvestsProvider = FutureProvider.family<List<Harvest>, String>((
  ref,
  cropId,
) {
  ref.watch(dataRevisionProvider);
  return ref.watch(harvestRepositoryProvider).getForCrop(cropId);
});

final dashboardProvider = FutureProvider<DashboardStats>((ref) async {
  final results = await Future.wait([
    ref.watch(plotsProvider.future),
    ref.watch(cropsProvider.future),
    ref.watch(allTasksProvider.future),
    ref.watch(allHarvestsProvider.future),
    ref.watch(financeEntriesProvider.future),
    ref.watch(allJournalProvider.future),
  ]);
  final converter = await ref.watch(areaConverterProvider.future);
  return DashboardCalculator.compute(
    plots: results[0] as List<Plot>,
    crops: results[1] as List<Crop>,
    tasks: results[2] as List<CropTask>,
    harvests: results[3] as List<Harvest>,
    financeEntries: results[4] as List<FinanceEntry>,
    journalEntries: results[5] as List<JournalEntry>,
    areaConverter: converter,
    today: ref.watch(todayProvider),
  );
});

final alertsProvider = FutureProvider<List<FarmAlert>>((ref) async {
  final crops = await ref.watch(cropsProvider.future);
  final tasks = await ref.watch(allTasksProvider.future);
  return AlertBuilder.build(
    crops: crops,
    tasks: tasks,
    today: ref.watch(todayProvider),
  );
});

final pendingSyncCountProvider = FutureProvider<int>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(dataMaintenanceProvider).pendingSyncCount();
});

final hasDemoDataProvider = FutureProvider<bool>((ref) {
  ref.watch(dataRevisionProvider);
  return ref.watch(dataMaintenanceProvider).hasDemoData();
});
