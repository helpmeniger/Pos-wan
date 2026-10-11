import 'package:flutter/material.dart';

import '../../../shared/widgets/common_widgets.dart';

/// Carte météo : aucune donnée n'est simulée. Tant qu'aucun fournisseur
/// météo n'est configuré (étape 7 du projet), un message explicite est
/// affiché.
class WeatherStatusCard extends StatelessWidget {
  const WeatherStatusCard({super.key});

  @override
  Widget build(BuildContext context) {
    return const SectionCard(
      title: 'Météo agricole',
      icon: Icons.cloud_outlined,
      child: InfoBanner(
        icon: Icons.cloud_off,
        message:
            'Aucun service météo n’est encore configuré dans cette '
            'version. Les prévisions s’afficheront ici lorsqu’une source '
            'météo fiable sera connectée (avec la date de mise à jour).',
      ),
    );
  }
}
