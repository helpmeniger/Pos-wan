import '../core/utils/date_utils.dart';
import '../data/models/enums.dart';
import '../data/models/finance_entry.dart';

/// Filtre appliqué aux écritures financières.
class FinanceFilter {
  const FinanceFilter({this.from, this.to, this.cropId, this.plotId});

  final DateTime? from;
  final DateTime? to;
  final String? cropId;
  final String? plotId;

  bool matches(FinanceEntry entry) {
    final date = DateOnly.of(entry.entryDate);
    if (from != null && date.isBefore(DateOnly.of(from!))) return false;
    if (to != null && date.isAfter(DateOnly.of(to!))) return false;
    if (cropId != null && entry.cropId != cropId) return false;
    if (plotId != null && entry.plotId != plotId) return false;
    return true;
  }
}

class FinanceSummary {
  const FinanceSummary({
    required this.totalExpenses,
    required this.totalIncomes,
    required this.byCategory,
    required this.entryCount,
  });

  final double totalExpenses;
  final double totalIncomes;
  final Map<FinanceCategory, double> byCategory;
  final int entryCount;

  /// Marge estimée = recettes − dépenses.
  double get margin => totalIncomes - totalExpenses;

  bool get isEmpty => entryCount == 0;
}

class FinanceCalculator {
  const FinanceCalculator._();

  static FinanceSummary summarize(
    Iterable<FinanceEntry> entries, {
    FinanceFilter filter = const FinanceFilter(),
  }) {
    var expenses = 0.0;
    var incomes = 0.0;
    var count = 0;
    final byCategory = <FinanceCategory, double>{};
    for (final entry in entries) {
      if (!filter.matches(entry)) continue;
      count++;
      if (entry.kind == FinanceKind.expense) {
        expenses += entry.amount;
      } else {
        incomes += entry.amount;
      }
      byCategory[entry.category] =
          (byCategory[entry.category] ?? 0) + entry.amount;
    }
    return FinanceSummary(
      totalExpenses: expenses,
      totalIncomes: incomes,
      byCategory: byCategory,
      entryCount: count,
    );
  }

  /// Coût de production par kg récolté (null si aucune récolte en kg).
  static double? productionCostPerKg(
    double totalExpenses,
    double harvestedKg,
  ) => harvestedKg > 0 ? totalExpenses / harvestedKg : null;
}
