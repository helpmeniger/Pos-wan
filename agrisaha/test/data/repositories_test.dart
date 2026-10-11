import 'dart:io';

import 'package:agrisaha/core/database/app_database.dart';
import 'package:agrisaha/core/database/migrations.dart';
import 'package:agrisaha/core/errors/app_exception.dart';
import 'package:agrisaha/data/catalog/crop_catalog.dart';
import 'package:agrisaha/data/models/enums.dart';
import 'package:agrisaha/data/repositories/crop_repository.dart';
import 'package:agrisaha/data/repositories/data_maintenance_service.dart';
import 'package:agrisaha/data/repositories/finance_repository.dart';
import 'package:agrisaha/data/repositories/harvest_repository.dart';
import 'package:agrisaha/data/repositories/journal_repository.dart';
import 'package:agrisaha/data/repositories/plot_repository.dart';
import 'package:agrisaha/data/repositories/profile_repository.dart';
import 'package:agrisaha/data/repositories/task_repository.dart';
import 'package:agrisaha/data/repositories/variety_repository.dart';
import 'package:agrisaha/domain/harvest_estimator.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/test_helpers.dart';

void main() {
  final now = DateTime.utc(2025, 8, 1, 10);
  final clock = fixedClock(now);
  late AppDatabase db;
  late PlotRepository plots;
  late CropRepository crops;
  late TaskRepository tasks;
  late HarvestRepository harvests;
  late FinanceRepository finances;

  setUp(() async {
    db = await openTestDatabase();
    plots = PlotRepository(db, clock: clock);
    crops = CropRepository(db, clock: clock);
    tasks = TaskRepository(db, clock: clock);
    harvests = HarvestRepository(db, clock: clock);
    finances = FinanceRepository(db, clock: clock);
  });

  tearDown(() => db.close());

  Future<String> createPlot({double area = 2}) async {
    final plot = await plots.create(
      name: 'Champ du nord',
      area: area,
      areaUnit: AreaUnit.hectare,
    );
    return plot.id;
  }

  CropDraft milletDraft(String plotId, {double area = 1.5, DateTime? sowing}) =>
      CropDraft(
        plotId: plotId,
        cropType: CropType.mil,
        varietyName: 'HKP',
        varietyRef: 'catalog:mil:hkp',
        seedType: SeedType.amelioree,
        area: area,
        areaUnit: AreaUnit.hectare,
        irrigationMode: IrrigationMode.pluvial,
        estimate: HarvestEstimator.fromVariety(
          sowing ?? DateTime.utc(2025, 7, 1),
          CropCatalog.varietyById('catalog:mil:hkp')!,
        ),
      );

  group('Parcelles', () {
    test('création, lecture et modification', () async {
      final id = await createPlot();
      final plot = (await plots.getById(id))!;
      expect(plot.name, 'Champ du nord');
      expect(plot.area, 2);
      await plots.update(plot.copyWith(name: 'Champ du sud'));
      expect((await plots.getAll()).single.name, 'Champ du sud');
    });

    test('validation côté dépôt', () async {
      expect(
        () => plots.create(name: ' ', area: 1, areaUnit: AreaUnit.hectare),
        throwsA(isA<ValidationException>()),
      );
      expect(
        () => plots.create(name: 'A', area: 0, areaUnit: AreaUnit.hectare),
        throwsA(isA<ValidationException>()),
      );
    });

    test('suppression refusée si la parcelle contient des cultures', () async {
      final id = await createPlot();
      await crops.create(milletDraft(id));
      expect(() => plots.delete(id), throwsA(isA<ValidationException>()));
    });
  });

  group('Cultures', () {
    test('création avec date estimée et calendrier', () async {
      final plotId = await createPlot();
      final crop = await crops.create(milletDraft(plotId));
      expect(crop.estimatedHarvestDate, DateTime.utc(2025, 9, 29));
      final stored = (await crops.getById(crop.id))!;
      expect(stored.maturationDays, 90);
      expect(stored.estimateSource.name, 'catalogVariety');
      final calendar = await tasks.getForCrop(crop.id);
      expect(calendar, isNotEmpty);
      expect(calendar.any((t) => t.templateKey == 'harvest'), isTrue);
    });

    test('superficie supérieure à la parcelle refusée', () async {
      final plotId = await createPlot(area: 1);
      expect(
        () => crops.create(milletDraft(plotId, area: 2)),
        throwsA(isA<ValidationException>()),
      );
      expect(await crops.getAll(), isEmpty);
    });

    test('parcelle inexistante refusée (aucune donnée orpheline)', () async {
      expect(
        () => crops.create(milletDraft('inconnue')),
        throwsA(isA<NotFoundException>()),
      );
      expect(await tasks.getAll(), isEmpty);
    });

    test('correction de la date estimée et décalage des tâches', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      final updated = await crops.correctHarvestDate(
        crop.id,
        DateTime.utc(2025, 10, 9),
      );
      expect(updated.maturationDays, 100);
      final harvestTask = (await tasks.getForCrop(crop.id))
          .firstWhere((t) => t.templateKey == 'harvest');
      expect(harvestTask.dueDate, DateTime.utc(2025, 10, 9));
    });

    test('suppression en cascade du calendrier', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      await crops.delete(crop.id);
      expect(await tasks.getForCrop(crop.id), isEmpty);
    });

    test('cycle observé à partir des récoltes réelles', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      await harvests.record(
        HarvestDraft(
          cropId: crop.id,
          harvestDate: DateTime.utc(2025, 7, 31),
          quantity: 10,
          unit: HarvestUnit.kg,
        ),
      );
      final observed = await crops.observedCycle(CropType.mil, 'hkp');
      expect(observed?.averageDays, 30);
      expect(observed?.sampleSize, 1);
    });
  });

  group('Tâches', () {
    test('confirmer, reporter et rouvrir', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      final task = (await tasks.getForCrop(crop.id))
          .firstWhere((t) => t.status == TaskStatus.todo);

      final done = await tasks.markDone(task.id, note: 'Fait avec 3 personnes');
      expect(done.status, TaskStatus.done);
      expect(done.note, 'Fait avec 3 personnes');
      expect(done.completedAt, now);

      final reopened = await tasks.reopen(task.id);
      expect(reopened.status, TaskStatus.todo);
      expect(reopened.completedAt, isNull);

      final length = task.windowEndDate.difference(task.dueDate).inDays;
      final postponed = await tasks.postpone(
        task.id,
        DateTime.utc(2025, 8, 10),
      );
      expect(postponed.status, TaskStatus.postponed);
      expect(postponed.dueDate, DateTime.utc(2025, 8, 10));
      expect(
        postponed.windowEndDate.difference(postponed.dueDate).inDays,
        length,
      );

      expect(
        () => tasks.postpone(task.id, DateTime.utc(2025, 7, 1)),
        throwsA(isA<ValidationException>()),
      );
    });
  });

  group('Récoltes', () {
    test('enregistrement : statut, recette et tâche « Récolte »', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      final harvest = await harvests.record(
        HarvestDraft(
          cropId: crop.id,
          harvestDate: DateTime.utc(2025, 7, 30),
          quantity: 20,
          unit: HarvestUnit.sac,
          kgPerUnit: 100,
          soldQuantity: 15,
          storedQuantity: 5,
          unitPrice: 20000,
        ),
      );
      expect(harvest.estimatedMaturationDays, 90);
      expect((await crops.getById(crop.id))!.status, CropStatus.harvested);
      final income = (await finances.getAll()).single;
      expect(income.amount, 300000);
      expect(income.harvestId, harvest.id);
      final harvestTask = (await tasks.getForCrop(crop.id))
          .firstWhere((t) => t.templateKey == 'harvest');
      expect(harvestTask.status, TaskStatus.done);

      await harvests.delete(harvest.id);
      expect((await crops.getById(crop.id))!.status, CropStatus.active);
      expect(await finances.getAll(), isEmpty);
    });

    test('récolte partielle : la culture reste en cours', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      await harvests.record(
        HarvestDraft(
          cropId: crop.id,
          harvestDate: DateTime.utc(2025, 7, 30),
          quantity: 5,
          unit: HarvestUnit.kg,
          isFinal: false,
        ),
      );
      expect((await crops.getById(crop.id))!.status, CropStatus.active);
    });

    test('incohérences refusées', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      expect(
        () => harvests.record(
          HarvestDraft(
            cropId: crop.id,
            harvestDate: DateTime.utc(2025, 7, 30),
            quantity: 10,
            unit: HarvestUnit.kg,
            soldQuantity: 8,
            storedQuantity: 5,
          ),
        ),
        throwsA(isA<ValidationException>()),
      );
      expect(
        () => harvests.record(
          HarvestDraft(
            cropId: crop.id,
            harvestDate: DateTime.utc(2025, 6, 1),
            quantity: 10,
            unit: HarvestUnit.kg,
          ),
        ),
        throwsA(isA<ValidationException>()),
      );
      expect(
        () => harvests.record(
          HarvestDraft(
            cropId: crop.id,
            harvestDate: DateTime.utc(2025, 8, 5),
            quantity: 10,
            unit: HarvestUnit.kg,
          ),
        ),
        throwsA(isA<ValidationException>()),
      );
      expect(await harvests.getForCrop(crop.id), isEmpty);
    });
  });

  group('Journal, finances, profil, variétés', () {
    test('journal chronologique', () async {
      final crop = await crops.create(milletDraft(await createPlot()));
      final journal = JournalRepository(db, clock: clock);
      await journal.add(
        cropId: crop.id,
        entryDate: DateTime.utc(2025, 7, 10),
        type: JournalEntryType.observation,
        title: 'Levée',
      );
      await journal.add(
        cropId: crop.id,
        entryDate: DateTime.utc(2025, 7, 20),
        type: JournalEntryType.irrigation,
        title: 'Arrosage',
        quantity: 200,
        quantityUnit: 'litres',
      );
      final entries = await journal.getForCrop(crop.id);
      expect(entries.map((e) => e.title), ['Arrosage', 'Levée']);
      expect(entries.first.quantity, 200);
    });

    test('dépense validée et enregistrée', () async {
      await finances.add(
        category: FinanceCategory.engrais,
        amount: 12500,
        entryDate: DateTime.utc(2025, 7, 5),
      );
      expect((await finances.getAll()).single.kind, FinanceKind.expense);
      expect(
        () => finances.add(
          category: FinanceCategory.engrais,
          amount: 0,
          entryDate: DateTime.utc(2025, 7, 5),
        ),
        throwsA(isA<ValidationException>()),
      );
    });

    test('profil unique, mis à jour', () async {
      final profiles = ProfileRepository(db, clock: clock);
      expect(await profiles.get(), isNull);
      await profiles.save(displayName: 'Moussa', region: 'Dosso');
      final updated = await profiles.save(
        displayName: 'Moussa A.',
        cropTypes: [CropType.mil, CropType.niebe],
        localAreaUnitLabel: 'corde',
        localAreaUnitHectares: 0.25,
      );
      final stored = (await profiles.get())!;
      expect(stored.id, updated.id);
      expect(stored.displayName, 'Moussa A.');
      expect(stored.cropTypes, [CropType.mil, CropType.niebe]);
      expect(stored.localAreaUnitHectares, 0.25);
      await profiles.deleteProfile();
      expect(await profiles.get(), isNull);
    });

    test('variété personnalisée unique par culture', () async {
      final varieties = VarietyRepository(db, clock: clock);
      await varieties.addUserVariety(
        cropType: CropType.sorgho,
        name: 'Locale rouge',
        cycleDays: 110,
      );
      final list = await varieties.getForCrop(CropType.sorgho);
      expect(list.last.name, 'Locale rouge');
      expect(list.last.isUserDefined, isTrue);
      expect(
        () => varieties.addUserVariety(
          cropType: CropType.sorgho,
          name: 'Locale rouge',
          cycleDays: 100,
        ),
        throwsA(isA<ValidationException>()),
      );
    });
  });

  group('Synchronisation (file d’attente)', () {
    test('une seule opération en attente par enregistrement', () async {
      final maintenance = DataMaintenanceService(db, clock: clock);
      final id = await createPlot();
      final plot = (await plots.getById(id))!;
      await plots.update(plot.copyWith(name: 'B'));
      await plots.update(plot.copyWith(name: 'C'));
      final rows = await db.db.query(
        'sync_outbox',
        where: 'entity = ? AND entity_id = ?',
        whereArgs: ['plots', id],
      );
      expect(rows, hasLength(1));
      expect(rows.single['operation'], 'upsert');
      await plots.delete(id);
      final afterDelete = await db.db.query(
        'sync_outbox',
        where: 'entity = ? AND entity_id = ?',
        whereArgs: ['plots', id],
      );
      expect(afterDelete.single['operation'], 'delete');
      expect(await maintenance.pendingSyncCount(), 1);
    });

    test('les données de démonstration ne sont pas synchronisées', () async {
      final maintenance = DataMaintenanceService(db, clock: clock);
      await maintenance.loadDemoData();
      expect(await maintenance.pendingSyncCount(), 0);
    });
  });

  group('Données de démonstration', () {
    test(
      'chargement puis suppression sans toucher aux données réelles',
      () async {
        final maintenance = DataMaintenanceService(db, clock: clock);
        final realPlot = await createPlot();
        await maintenance.loadDemoData();
        await maintenance.loadDemoData(); // idempotent
        expect(await maintenance.hasDemoData(), isTrue);
        expect((await plots.getAll()).length, 3);
        expect((await crops.getAll()).length, 2);

        await maintenance.clearDemoData();
        expect(await maintenance.hasDemoData(), isFalse);
        expect((await plots.getAll()).single.id, realPlot);
        expect(await crops.getAll(), isEmpty);
        expect(await finances.getAll(), isEmpty);
      },
    );

    test('effacement complet', () async {
      final maintenance = DataMaintenanceService(db, clock: clock);
      await crops.create(milletDraft(await createPlot()));
      await maintenance.deleteAllData();
      expect(await plots.getAll(), isEmpty);
      expect(await tasks.getAll(), isEmpty);
    });
  });

  test('persistance après fermeture et réouverture de la base', () async {
    final dir = await Directory.systemTemp.createTemp('agrisaha_test');
    final path = '${dir.path}/agrisaha.db';
    try {
      var fileDb = await openTestDatabase(path: path);
      final crop = await CropRepository(fileDb, clock: clock).create(
        milletDraft(
          (await PlotRepository(
            fileDb,
            clock: clock,
          ).create(name: 'P', area: 2, areaUnit: AreaUnit.hectare)).id,
        ),
      );
      await fileDb.close();

      fileDb = await openTestDatabase(path: path);
      final reloaded = await CropRepository(fileDb).getById(crop.id);
      expect(reloaded?.estimatedHarvestDate, crop.estimatedHarvestDate);
      expect(await TaskRepository(fileDb).getForCrop(crop.id), isNotEmpty);
      final version = (await fileDb.db.rawQuery("PRAGMA user_version"))
          .first
          .values
          .first;
      expect(version, schemaVersion);
      await fileDb.close();
    } finally {
      await dir.delete(recursive: true);
    }
  });
}
