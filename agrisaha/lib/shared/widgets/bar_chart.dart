import 'package:flutter/material.dart';

import '../../app/theme/app_theme.dart';

class BarDatum {
  const BarDatum({
    required this.label,
    required this.value,
    required this.valueLabel,
    this.color = AppColors.green,
  });

  final String label;
  final double value;
  final String valueLabel;
  final Color color;
}

/// Graphique en barres horizontales simple, lisible sur petit écran et sans
/// dépendance externe.
class HorizontalBarChart extends StatelessWidget {
  const HorizontalBarChart({super.key, required this.data});

  final List<BarDatum> data;

  @override
  Widget build(BuildContext context) {
    final max = data.fold<double>(0, (m, d) => d.value > m ? d.value : m);
    final theme = Theme.of(context);
    return Column(
      children: [
        for (final datum in data)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Semantics(
              label: '${datum.label} : ${datum.valueLabel}',
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          datum.label,
                          style: theme.textTheme.bodyMedium,
                        ),
                      ),
                      Text(
                        datum.valueLabel,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: LinearProgressIndicator(
                      value: max <= 0 ? 0 : (datum.value / max).clamp(0, 1),
                      minHeight: 14,
                      color: datum.color,
                      backgroundColor: AppColors.sand,
                    ),
                  ),
                ],
              ),
            ),
          ),
      ],
    );
  }
}
