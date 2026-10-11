import 'package:agrisaha/core/utils/date_utils.dart';
import 'package:agrisaha/data/models/enums.dart';
import 'package:agrisaha/domain/calendar_generator.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  var counter = 0;
  String newId() => 'id${counter++}';

  test('mil pluvial : démariage présent, aucune irrigation', () {
    final tasks = CalendarGenerator.generate(
      cropId: 'c',
      cropType: CropType.mil,
      irrigationMode: IrrigationMode.pluvial,
      sowingDate: DateTime.utc(2025, 7, 1),
      estimatedHarvestDate: DateTime.utc(2025, 9, 29),
      now: DateTime.utc(2025, 6, 20),
      newId: newId,
    );
    final keys = tasks.map((t) => t.templateKey).toList();
    expect(keys, contains('thinning'));
    expect(keys, contains('harvest'));
    expect(keys, contains('drying_storage'));
    expect(keys.any((k) => k.startsWith('irrigation_check')), isFalse);
    expect(keys, isNot(contains('transplant_check')));
    // Calendrier trié par date.
    for (var i = 1; i < tasks.length; i++) {
      expect(tasks[i].dueDate.isBefore(tasks[i - 1].dueDate), isFalse);
    }
    // Tous à faire puisque le semis est futur.
    expect(tasks.every((t) => t.status == TaskStatus.todo), isTrue);
  });

  test('oignon irrigué : contrôles d’irrigation, pas de démariage', () {
    final tasks = CalendarGenerator.generate(
      cropId: 'c',
      cropType: CropType.oignon,
      irrigationMode: IrrigationMode.irrigation,
      sowingDate: DateTime.utc(2025, 11, 1),
      estimatedHarvestDate: DateTime.utc(2026, 2, 19),
      now: DateTime.utc(2025, 11, 1),
      newId: newId,
    );
    final keys = tasks.map((t) => t.templateKey).toList();
    expect(
      keys.where((k) => k.startsWith('irrigation_check')).length,
      greaterThan(5),
    );
    expect(keys, isNot(contains('thinning')));
    expect(keys, contains('transplant_check'));
    expect(keys, contains('sorting_storage'));
  });

  test('les tâches répétées s’arrêtent avant la récolte', () {
    final harvest = DateTime.utc(2025, 9, 29);
    final tasks = CalendarGenerator.generate(
      cropId: 'c',
      cropType: CropType.mil,
      irrigationMode: IrrigationMode.mixte,
      sowingDate: DateTime.utc(2025, 7, 1),
      estimatedHarvestDate: harvest,
      now: DateTime.utc(2025, 7, 1),
      newId: newId,
    );
    final pest = tasks.where((t) => t.templateKey.startsWith('pest_watch'));
    expect(pest, isNotEmpty);
    for (final task in pest) {
      expect(task.dueDate.isAfter(DateOnly.addDays(harvest, -14)), isFalse);
    }
    final irrigation = tasks.where(
      (t) => t.templateKey.startsWith('irrigation_check'),
    );
    for (final task in irrigation) {
      expect(task.dueDate.isAfter(DateOnly.addDays(harvest, -10)), isFalse);
    }
  });

  test('culture enregistrée après le semis : semis effectué, tâches passées '
      'non suivies', () {
    final now = DateTime.utc(2025, 8, 10);
    final tasks = CalendarGenerator.generate(
      cropId: 'c',
      cropType: CropType.sorgho,
      irrigationMode: IrrigationMode.pluvial,
      sowingDate: DateTime.utc(2025, 7, 1),
      estimatedHarvestDate: DateTime.utc(2025, 10, 19),
      now: now,
      newId: newId,
    );
    final sowing = tasks.firstWhere((t) => t.templateKey == 'sowing');
    expect(sowing.status, TaskStatus.done);
    final soil = tasks.firstWhere((t) => t.templateKey == 'soil_preparation');
    expect(soil.status, TaskStatus.notTracked);
    final harvestTask = tasks.firstWhere((t) => t.templateKey == 'harvest');
    expect(harvestTask.status, TaskStatus.todo);
    expect(harvestTask.dueDate, DateTime.utc(2025, 10, 19));
    expect(tasks.where((t) => t.isOverdue(now)), isEmpty);
  });

  test('correction de la date de récolte : seules les tâches de fin de cycle '
      'à faire sont décalées', () {
    final tasks = CalendarGenerator.generate(
      cropId: 'c',
      cropType: CropType.mil,
      irrigationMode: IrrigationMode.pluvial,
      sowingDate: DateTime.utc(2025, 7, 1),
      estimatedHarvestDate: DateTime.utc(2025, 9, 29),
      now: DateTime.utc(2025, 7, 1),
      newId: newId,
    );
    final shifted = CalendarGenerator.shiftHarvestAnchored(
      tasks,
      DateTime.utc(2025, 10, 9),
      DateTime.utc(2025, 8, 1),
    );
    expect(shifted.every((t) => t.anchor == TaskAnchor.harvest), isTrue);
    final harvest = shifted.firstWhere((t) => t.templateKey == 'harvest');
    expect(harvest.dueDate, DateTime.utc(2025, 10, 9));
    expect(harvest.windowEndDate, DateTime.utc(2025, 10, 16));
  });
}
