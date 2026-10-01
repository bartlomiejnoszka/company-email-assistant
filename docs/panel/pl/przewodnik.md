# Przewodnik właściciela

## Company

W `company.yaml` wpisz dane firmy, język odpowiedzi, strefę czasową i zatwierdzony podpis. Nazwy pól pozostają angielskie. Tryb `literal` zachowuje treści bez zmian; `mixed` dopuszcza redakcję zatwierdzonych odpowiedzi i pytań. Ceny, warunki i podpis pozostają dosłowne.

## Knowledge

W `knowledge.yaml` dodaj tematy, słowa rozpoznawania, zatwierdzone odpowiedzi i pytania o brakujące dane. Nie zmieniaj stabilnych identyfikatorów bez sprawdzenia odwołań. Niepewne zapytania wymagają obsługi przez pracownika.

## Pricing

W `pricing.yaml` dodawaj wyłącznie zatwierdzone ceny i pełne warunki. Do tego czasu pozostaw `prices: {}`. Kwoty i daty zapisuj w cudzysłowie. Aplikacja nie oblicza indywidualnych sum ani podatków.

## Zapis i przywracanie

Zapis obejmuje trzy pliki. Błąd walidacji lub konflikt wersji nie zmieni aktywnej konfiguracji. Przed ponownym wczytaniem skopiuj własne zmiany. Przywrócenie tworzy nową wersję z poprzedniego zestawu. Wcześniejsze szkice pozostają bez zmian.
