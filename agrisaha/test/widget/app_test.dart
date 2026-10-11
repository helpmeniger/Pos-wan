import 'package:agrisaha/app/app.dart';
import 'package:agrisaha/core/database/app_database.dart';
import 'package:agrisaha/core/providers/providers.dart';
import 'package:agrisaha/data/models/enums.dart';
import 'package:agrisaha/data/repositories/data_maintenance_service.dart';
import 'package:agrisaha/data/repositories/plot_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import '../helpers/test_helpers.dart';

void main() {
  final now = DateTime.utc(2025, 8, 1, 9);
  late AppDatabase db;

  setUpAll(() => initializeDateFormatting('fr_FR'));

  setUp(() async {
    db = await openTestDatabase();
  });

  tearDown(() => db.close());

  Future<void> pumpApp(WidgetTester tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.5;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        retry: (_, _) => null,
        overrides: [
          databaseProvider.overrideWithValue(db),
          clockProvider.overrideWithValue(fixedClock(now)),
        ],
        child: const AgriSahaApp(),
      ),
    );
    await settle(tester);
  }

  testWidgets('accueil vide utile et navigation inférieure', (tester) async {
    await pumpApp(tester);
    expect(find.text('AgriSaha'), findsOneWidget);
    expect(find.text(AgriSahaApp.slogan), findsOneWidget);
    expect(find.text('Commencez par votre première parcelle'), findsOneWidget);

    for (final tab in ['Mes cultures', 'Alertes', 'Marché', 'Profil']) {
      await tester.tap(find.text(tab).last);
      await settle(tester);
    }
    expect(find.text('Aucun profil'), findsOneWidget);

    await tester.tap(find.text('Mes cultures').last);
    await settle(tester);
    expect(find.text('Aucune culture en cours'), findsOneWidget);

    await tester.tap(find.text('Alertes').last);
    await settle(tester);
    expect(find.text('Aucune alerte'), findsOneWidget);
  });

  testWidgets('formulaire de parcelle : validation puis enregistrement', (
    tester,
  ) async {
    await pumpApp(tester);
    await tester.drag(find.byType(ListView).first, const Offset(0, -500));
    await settle(tester);
    await tester.tap(find.text('Ajouter une parcelle'));
    await settle(tester);
    expect(find.text('Nouvelle parcelle'), findsOneWidget);

    await tester.tap(find.text('Enregistrer'));
    await settle(tester);
    expect(find.text('Le nom de la parcelle est obligatoire.'), findsOneWidget);
    expect(find.text('La superficie est obligatoire.'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Nom de la parcelle *'),
      'Champ A',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Superficie *'),
      '1,5',
    );
    await tester.tap(find.text('Enregistrer'));
    await settle(tester);

    final plots = await PlotRepository(db).getAll();
    expect(plots.single.name, 'Champ A');
    expect(plots.single.area, 1.5);
    expect(plots.single.areaUnit, AreaUnit.hectare);
  });

  testWidgets('tableau de bord alimenté par les données de démonstration', (
    tester,
  ) async {
    await DataMaintenanceService(db, clock: fixedClock(now)).loadDemoData();
    await pumpApp(tester);
    await tester.drag(find.byType(ListView).first, const Offset(0, -1500));
    await settle(tester);
    await tester.tap(find.text('Voir le tableau de bord complet'));
    await settle(tester);
    expect(find.text('Tableau de bord'), findsOneWidget);
    expect(find.text('Parcelles enregistrées'), findsOneWidget);
    // 40 sacs de 50 kg récoltés dans l'exemple.
    expect(find.textContaining(RegExp(r'^2\s000 kg$')), findsWidgets);
  });
}

/// Laisse les requêtes SQLite (asynchrones) se terminer puis redessine.
Future<void> settle(WidgetTester tester) async {
  for (var i = 0; i < 5; i++) {
    await tester.runAsync(() => Future<void>.delayed(Duration.zero));
    await tester.pumpAndSettle();
  }
}
