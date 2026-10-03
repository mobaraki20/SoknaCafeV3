<?php
declare(strict_types=1);

namespace Sokna\Local\Domain\Sellables;

use PDO;

final class SellableRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(array $data): int
    {
        $categoryId = (int)($data['category_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $price = (int)($data['price'] ?? 0);
        $kind = SellableKind::requireWrite($data['sellable_kind'] ?? SellableKind::MENU_ITEM);
        if ($categoryId < 1 || $name === '' || $price < 0) {
            throw new \InvalidArgumentException('نام، دسته‌بندی و قیمت معتبر لازم است.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO items(item_code,category_id,name,description,price,image_path,available,active,featured,staff_only,' .
            'sellable_kind,takeaway_allowed,preparation_station,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            self::nullableText($data['item_code'] ?? null),
            $categoryId,
            $name,
            (string)($data['description'] ?? ''),
            $price,
            self::nullableText($data['image_path'] ?? null),
            self::flag($data['available'] ?? true),
            self::flag($data['active'] ?? true),
            self::flag($data['featured'] ?? false),
            self::flag($data['staff_only'] ?? false),
            $kind,
            self::flag($data['takeaway_allowed'] ?? true),
            trim((string)($data['preparation_station'] ?? 'other')) ?: 'other',
            (int)($data['sort_order'] ?? 0),
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if (self::nullableText($data['item_code'] ?? null) === null) {
            $this->pdo->prepare('UPDATE items SET item_code=? WHERE id=?')->execute(['ITEM-' . $id, $id]);
        }
        return $id;
    }

    public function find(int $id): ?array
    {
        if ($id < 1) return null;
        $stmt = $this->pdo->prepare('SELECT * FROM items WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['sellable_kind'] = SellableKind::normalizeRead($row['sellable_kind'] ?? null);
        $row['sellable_kind_label'] = SellableKind::label($row['sellable_kind']);
        return $row;
    }

    public function changeKind(int $id, mixed $kind): void
    {
        if ($id < 1) throw new \InvalidArgumentException('آیتم معتبر نیست.');
        $required = SellableKind::requireWrite($kind);
        $stmt = $this->pdo->prepare('UPDATE items SET sellable_kind=? WHERE id=?');
        $stmt->execute([$required, $id]);
        if ($stmt->rowCount() === 0 && $this->find($id) === null) {
            throw new \RuntimeException('آیتم پیدا نشد.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function catalogRows(bool $includeInactive = false): array
    {
        $sql = 'SELECT i.*,c.category_key,c.name AS category_name FROM items i JOIN categories c ON c.id=i.category_id';
        if (!$includeInactive) $sql .= ' WHERE i.active=1';
        $sql .= ' ORDER BY c.sort_order,c.id,i.sort_order,i.id';
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['sellable_kind'] = SellableKind::normalizeRead($row['sellable_kind'] ?? null);
            $row['sellable_kind_label'] = SellableKind::label($row['sellable_kind']);
        }
        unset($row);
        return array_values($rows);
    }

    private static function flag(mixed $value): int
    {
        return filter_var($value, FILTER_VALIDATE_BOOL) ? 1 : 0;
    }

    private static function nullableText(mixed $value): ?string
    {
        $text = trim((string)$value);
        return $text === '' ? null : $text;
    }
}
