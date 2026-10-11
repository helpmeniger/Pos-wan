import 'package:flutter/material.dart';

import '../../shared/widgets/common_widgets.dart';

/// Marché agricole : la publication et la consultation d'annonces
/// nécessitent un serveur (backend) sécurisé qui n'est pas encore
/// configuré. Aucun bouton factice n'est proposé.
class MarketScreen extends StatelessWidget {
  const MarketScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Marché agricole')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: const [
          EmptyState(
            icon: Icons.storefront,
            title: 'Bientôt disponible',
            message:
                'Le marché permettra de publier vos produits (céréales, '
                'légumineuses, légumes, fruits, produits transformés, '
                'intrants) et de contacter acheteurs, commerçants et '
                'coopératives.',
          ),
          InfoBanner(
            icon: Icons.cloud_off,
            message:
                'Ce module nécessite une connexion Internet et un serveur '
                'sécurisé (contrôle des annonces, protection de vos '
                'coordonnées, lutte contre la fraude). Il sera activé lors '
                'd’une prochaine étape du projet. Vos récoltes enregistrées '
                'pourront alors être proposées à la vente.',
          ),
        ],
      ),
    );
  }
}
