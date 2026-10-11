import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'app/app.dart';
import 'app/theme/app_theme.dart';
import 'core/database/app_database.dart';
import 'core/errors/app_exception.dart';
import 'core/providers/providers.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('fr_FR');

  final AppDatabase database;
  try {
    database = await AppDatabase.open();
  } catch (error, stack) {
    ErrorHandler.log(error, stack);
    runApp(const _StartupErrorApp());
    return;
  }

  runApp(
    ProviderScope(
      // Les erreurs de lecture locale sont affichées immédiatement plutôt
      // que réessayées en boucle.
      retry: (retryCount, error) => null,
      overrides: [databaseProvider.overrideWithValue(database)],
      child: const AgriSahaApp(),
    ),
  );
}

/// Écran affiché si la base locale ne peut pas être ouverte (stockage
/// plein, fichier corrompu…). Aucune donnée n'est effacée automatiquement.
class _StartupErrorApp extends StatelessWidget {
  const _StartupErrorApp();

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      home: const Scaffold(
        body: SafeArea(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Center(
              child: Text(
                'AgriSaha ne parvient pas à ouvrir ses données sur ce '
                'téléphone. Vérifiez l’espace de stockage disponible puis '
                'redémarrez l’application. Vos données n’ont pas été effacées.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 17),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
