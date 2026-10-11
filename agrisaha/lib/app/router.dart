import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../features/alerts/alerts_screen.dart';
import '../features/crop_tracking/crop_detail_screen.dart';
import '../features/crop_tracking/crop_form_screen.dart';
import '../features/crop_tracking/crops_screen.dart';
import '../features/crop_tracking/journal_entry_form_screen.dart';
import '../features/dashboard/dashboard_screen.dart';
import '../features/finances/finance_entry_form_screen.dart';
import '../features/finances/finances_screen.dart';
import '../features/harvests/harvest_form_screen.dart';
import '../features/home/home_screen.dart';
import '../features/marketplace/market_screen.dart';
import '../features/plots/plot_form_screen.dart';
import '../features/plots/plots_screen.dart';
import '../features/profile/profile_form_screen.dart';
import '../features/profile/profile_screen.dart';
import 'shell_scaffold.dart';

/// Chemins de navigation de l'application.
class Routes {
  const Routes._();

  static const home = '/accueil';
  static const crops = '/cultures';
  static const alerts = '/alertes';
  static const market = '/marche';
  static const profile = '/profil';

  static const dashboard = '/tableau-de-bord';
  static const plots = '/parcelles';
  static const newPlot = '/parcelles/nouvelle';
  static String editPlot(String id) => '/parcelles/$id/modifier';
  static const newCrop = '/culture/nouvelle';
  static String crop(String id) => '/culture/$id';
  static String newHarvest(String cropId) => '/culture/$cropId/recolte';
  static String newJournalEntry(String cropId) => '/culture/$cropId/journal';
  static const finances = '/finances';
  static const newFinanceEntry = '/finances/nouvelle';
  static const editProfile = '/profil/modifier';
}

final rootNavigatorKey = GlobalKey<NavigatorState>();

GoRouter buildRouter() {
  return GoRouter(
    navigatorKey: rootNavigatorKey,
    initialLocation: Routes.home,
    routes: [
      StatefulShellRoute.indexedStack(
        builder: (context, state, shell) => ShellScaffold(shell: shell),
        branches: [
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.home,
                builder: (context, state) => const HomeScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.crops,
                builder: (context, state) => const CropsScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.alerts,
                builder: (context, state) => const AlertsScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.market,
                builder: (context, state) => const MarketScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            routes: [
              GoRoute(
                path: Routes.profile,
                builder: (context, state) => const ProfileScreen(),
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: Routes.dashboard,
        builder: (context, state) => const DashboardScreen(),
      ),
      GoRoute(
        path: Routes.plots,
        builder: (context, state) => const PlotsScreen(),
      ),
      GoRoute(
        path: Routes.newPlot,
        builder: (context, state) => const PlotFormScreen(),
      ),
      GoRoute(
        path: '/parcelles/:plotId/modifier',
        builder: (context, state) =>
            PlotFormScreen(plotId: state.pathParameters['plotId']),
      ),
      GoRoute(
        path: Routes.newCrop,
        builder: (context, state) => CropFormScreen(
          initialPlotId: state.uri.queryParameters['parcelle'],
        ),
      ),
      GoRoute(
        path: '/culture/:cropId',
        builder: (context, state) =>
            CropDetailScreen(cropId: state.pathParameters['cropId']!),
      ),
      GoRoute(
        path: '/culture/:cropId/recolte',
        builder: (context, state) =>
            HarvestFormScreen(cropId: state.pathParameters['cropId']!),
      ),
      GoRoute(
        path: '/culture/:cropId/journal',
        builder: (context, state) =>
            JournalEntryFormScreen(cropId: state.pathParameters['cropId']!),
      ),
      GoRoute(
        path: Routes.finances,
        builder: (context, state) => const FinancesScreen(),
      ),
      GoRoute(
        path: Routes.newFinanceEntry,
        builder: (context, state) => FinanceEntryFormScreen(
          initialCropId: state.uri.queryParameters['culture'],
        ),
      ),
      GoRoute(
        path: Routes.editProfile,
        builder: (context, state) => const ProfileFormScreen(),
      ),
    ],
  );
}
