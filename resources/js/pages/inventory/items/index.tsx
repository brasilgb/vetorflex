import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import FormField from '@/components/form-field';
import Heading from '@/components/heading';
import { inventoryBreadcrumb } from '@/components/inventory/inventory-nav';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDecimal, parseDecimal } from '@/lib/decimal';
import {
    exportMethod,
    importMethod,
    index,
    show,
    store,
    template,
} from '@/routes/inventory/items';
import type {
    NamedRecord,
    Paginated,
    StockItem,
    StockWarehouseOption,
} from '@/types';

type Unit = { code: string; name: string; allows_fraction: boolean };
type ImportResult = {
    created: number;
    skipped: { line: number; message: string }[];
    errors: { line: number; message: string }[];
};

const SITUATIONS = [
    { value: '', label: 'Todas' },
    { value: 'low', label: 'Abaixo do mínimo' },
    { value: 'above_max', label: 'Acima do máximo' },
    { value: 'zero', label: 'Sem saldo' },
    { value: 'with_balance', label: 'Com saldo' },
    { value: 'inactive', label: 'Inativas' },
];

function CreateItemDialog({
    categories,
    units,
    warehouses,
    canViewCost,
}: {
    categories: NamedRecord[];
    units: Unit[];
    warehouses: StockWarehouseOption[];
    canViewCost: boolean;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const initial = {
        name: '',
        sku: '',
        barcode: '',
        internal_code: '',
        product_category_id: '',
        category_name: '',
        unit_code: 'UN',
        minimum_stock: '',
        maximum_stock: '',
        cost_price: '',
        sale_price: '',
        initial_stock: '',
        inventory_warehouse_id: String(
            warehouses.find((warehouse) => warehouse.is_default)?.id ??
                warehouses[0]?.id ??
                '',
        ),
    };
    const form = useForm(initial);
    const decimal = (value: string): string | null =>
        value.trim() === '' ? null : parseDecimal(value);

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.setData(initial);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button>Nova mercadoria</Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            sku: data.sku || null,
                            barcode: data.barcode || null,
                            internal_code: data.internal_code || null,
                            product_category_id:
                                data.product_category_id === ''
                                    ? null
                                    : Number(data.product_category_id),
                            category_name: data.category_name || null,
                            minimum_stock: decimal(data.minimum_stock),
                            maximum_stock: decimal(data.maximum_stock),
                            cost_price: canViewCost
                                ? decimal(data.cost_price)
                                : undefined,
                            sale_price: decimal(data.sale_price),
                            initial_stock: decimal(data.initial_stock),
                            inventory_warehouse_id:
                                data.inventory_warehouse_id === ''
                                    ? null
                                    : Number(data.inventory_warehouse_id),
                        }));
                        form.post(store.url(), {
                            preserveScroll: true,
                            onSuccess: () => setIsOpen(false),
                        });
                    }}
                >
                    <DialogTitle>Nova mercadoria</DialogTitle>
                    <DialogDescription>
                        Cadastro simplificado. A mercadoria fica disponível
                        também para Compras, Vendas e PDV, se contratados.
                    </DialogDescription>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FormField
                            id="item-name"
                            label="Nome"
                            error={form.errors.name}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="item-name"
                                required
                                maxLength={160}
                                autoFocus
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="item-sku"
                            label="SKU (gerado se vazio)"
                            error={form.errors.sku}
                        >
                            <Input
                                id="item-sku"
                                maxLength={60}
                                value={form.data.sku}
                                onChange={(event) =>
                                    form.setData('sku', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="item-barcode"
                            label="Código de barras"
                            error={form.errors.barcode}
                        >
                            <Input
                                id="item-barcode"
                                maxLength={60}
                                value={form.data.barcode}
                                onChange={(event) =>
                                    form.setData('barcode', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="item-internal"
                            label="Código interno"
                            error={form.errors.internal_code}
                        >
                            <Input
                                id="item-internal"
                                maxLength={60}
                                value={form.data.internal_code}
                                onChange={(event) =>
                                    form.setData(
                                        'internal_code',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="item-unit"
                            label="Unidade"
                            error={
                                (form.errors as Record<string, string>)
                                    .unit_code
                            }
                        >
                            <NativeSelect
                                id="item-unit"
                                value={form.data.unit_code}
                                onChange={(event) =>
                                    form.setData(
                                        'unit_code',
                                        event.target.value,
                                    )
                                }
                            >
                                {units.map((unit) => (
                                    <option key={unit.code} value={unit.code}>
                                        {unit.code} — {unit.name}
                                        {unit.allows_fraction
                                            ? ' (fracionável)'
                                            : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        <FormField
                            id="item-category"
                            label="Categoria"
                            error={
                                (form.errors as Record<string, string>)
                                    .product_category_id
                            }
                        >
                            <NativeSelect
                                id="item-category"
                                value={form.data.product_category_id}
                                onChange={(event) =>
                                    form.setData(
                                        'product_category_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Sem categoria ou nova…</option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                        {form.data.product_category_id === '' && (
                            <FormField
                                id="item-new-category"
                                label="Nova categoria (opcional)"
                                error={
                                    (form.errors as Record<string, string>)
                                        .category_name
                                }
                            >
                                <Input
                                    id="item-new-category"
                                    maxLength={80}
                                    value={form.data.category_name}
                                    onChange={(event) =>
                                        form.setData(
                                            'category_name',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                        <FormField
                            id="item-minimum"
                            label="Estoque mínimo"
                            error={form.errors.minimum_stock}
                        >
                            <Input
                                id="item-minimum"
                                inputMode="decimal"
                                value={form.data.minimum_stock}
                                onChange={(event) =>
                                    form.setData(
                                        'minimum_stock',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="item-maximum"
                            label="Estoque máximo (opcional)"
                            error={form.errors.maximum_stock}
                        >
                            <Input
                                id="item-maximum"
                                inputMode="decimal"
                                value={form.data.maximum_stock}
                                onChange={(event) =>
                                    form.setData(
                                        'maximum_stock',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        {canViewCost && (
                            <FormField
                                id="item-cost"
                                label="Custo unitário"
                                error={form.errors.cost_price}
                            >
                                <Input
                                    id="item-cost"
                                    inputMode="decimal"
                                    placeholder="0,00"
                                    value={form.data.cost_price}
                                    onChange={(event) =>
                                        form.setData(
                                            'cost_price',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        )}
                        <FormField
                            id="item-price"
                            label="Preço de venda (opcional)"
                            error={form.errors.sale_price}
                        >
                            <Input
                                id="item-price"
                                inputMode="decimal"
                                placeholder="0,00"
                                value={form.data.sale_price}
                                onChange={(event) =>
                                    form.setData(
                                        'sale_price',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="item-initial"
                            label="Saldo inicial"
                            error={form.errors.initial_stock}
                        >
                            <Input
                                id="item-initial"
                                inputMode="decimal"
                                value={form.data.initial_stock}
                                onChange={(event) =>
                                    form.setData(
                                        'initial_stock',
                                        event.target.value,
                                    )
                                }
                            />
                        </FormField>
                        <FormField
                            id="item-warehouse"
                            label="Depósito do saldo inicial"
                            error={form.errors.inventory_warehouse_id}
                        >
                            <NativeSelect
                                id="item-warehouse"
                                value={form.data.inventory_warehouse_id}
                                onChange={(event) =>
                                    form.setData(
                                        'inventory_warehouse_id',
                                        event.target.value,
                                    )
                                }
                            >
                                {warehouses.map((warehouse) => (
                                    <option
                                        key={warehouse.id}
                                        value={warehouse.id}
                                    >
                                        {warehouse.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </FormField>
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button disabled={form.processing}>Cadastrar</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ImportDialog() {
    const [isOpen, setIsOpen] = useState(false);
    const form = useForm<{ file: File | null }>({ file: null });
    const result = (
        usePage().flash as { importResult?: ImportResult } | undefined
    )?.importResult;

    return (
        <Dialog
            open={isOpen}
            onOpenChange={(state) => {
                setIsOpen(state);
                form.reset();
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">Importar CSV</Button>
            </DialogTrigger>
            <DialogContent>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(importMethod.url(), {
                            preserveScroll: true,
                            forceFormData: true,
                        });
                    }}
                >
                    <DialogTitle>Importar mercadorias</DialogTitle>
                    <DialogDescription>
                        Arquivo CSV separado por ponto e vírgula. Cada linha
                        nova cria uma mercadoria com saldo inicial opcional; SKU
                        ou código de barras já existentes são ignorados.{' '}
                        <a
                            href={template.url()}
                            className="underline underline-offset-4"
                        >
                            Baixar modelo
                        </a>
                    </DialogDescription>
                    <FormField
                        id="import-file"
                        label="Arquivo"
                        error={form.errors.file}
                    >
                        <Input
                            id="import-file"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </FormField>
                    {result && (
                        <div className="space-y-1 rounded-md border p-3 text-sm">
                            <p>
                                {result.created} cadastrada(s),{' '}
                                {result.skipped.length} ignorada(s),{' '}
                                {result.errors.length} com erro.
                            </p>
                            {[...result.errors, ...result.skipped]
                                .slice(0, 20)
                                .map((row, position) => (
                                    <p
                                        key={position}
                                        className="text-xs text-muted-foreground"
                                    >
                                        Linha {row.line}: {row.message}
                                    </p>
                                ))}
                        </div>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="secondary">
                                Fechar
                            </Button>
                        </DialogClose>
                        <Button
                            disabled={
                                form.processing || form.data.file === null
                            }
                        >
                            Importar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function StockItems({
    items,
    filters,
    categories,
    units,
    warehouses,
    canManage,
    canImportExport,
    canViewCost,
}: {
    items: Paginated<StockItem>;
    filters: {
        search?: string | null;
        category: number | null;
        situation?: string | null;
        warehouse: number | null;
    };
    categories: NamedRecord[];
    units: Unit[];
    warehouses: StockWarehouseOption[];
    canManage: boolean;
    canImportExport: boolean;
    canViewCost: boolean;
}) {
    const form = useForm({
        search: filters.search ?? '',
        category: filters.category === null ? '' : String(filters.category),
        situation: filters.situation ?? '',
        warehouse: filters.warehouse === null ? '' : String(filters.warehouse),
    });

    return (
        <>
            <Head title="Mercadorias" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Mercadorias"
                        description="Saldo total, reservado e disponível de cada mercadoria com controle de estoque."
                    />
                    <div className="flex flex-wrap gap-2">
                        {canImportExport && (
                            <Button variant="outline" asChild>
                                <a href={exportMethod.url()}>Exportar CSV</a>
                            </Button>
                        )}
                        {canImportExport && canManage && <ImportDialog />}
                        {canManage && (
                            <CreateItemDialog
                                categories={categories}
                                units={units}
                                warehouses={warehouses}
                                canViewCost={canViewCost}
                            />
                        )}
                    </div>
                </div>
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) =>
                            Object.fromEntries(
                                Object.entries(data).filter(
                                    ([, value]) => value !== '',
                                ),
                            ),
                        );
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        aria-label="Buscar"
                        placeholder="Nome, SKU ou código de barras"
                        className="w-64"
                        value={form.data.search}
                        onChange={(event) =>
                            form.setData('search', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Situação"
                        className="w-44"
                        value={form.data.situation}
                        onChange={(event) =>
                            form.setData('situation', event.target.value)
                        }
                    >
                        {SITUATIONS.map((situation) => (
                            <option
                                key={situation.value}
                                value={situation.value}
                            >
                                {situation.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Categoria"
                        className="w-44"
                        value={form.data.category}
                        onChange={(event) =>
                            form.setData('category', event.target.value)
                        }
                    >
                        <option value="">Todas as categorias</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Depósito"
                        className="w-44"
                        value={form.data.warehouse}
                        onChange={(event) =>
                            form.setData('warehouse', event.target.value)
                        }
                    >
                        <option value="">Todos os depósitos</option>
                        {warehouses.map((warehouse) => (
                            <option key={warehouse.id} value={warehouse.id}>
                                {warehouse.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button variant="outline" disabled={form.processing}>
                        Filtrar
                    </Button>
                </form>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3">Mercadoria</th>
                                <th className="p-3">Categoria</th>
                                {filters.warehouse !== null && (
                                    <th className="p-3 text-right">
                                        No depósito
                                    </th>
                                )}
                                <th className="p-3 text-right">Saldo</th>
                                <th className="p-3 text-right">Reservado</th>
                                <th className="p-3 text-right">Disponível</th>
                                <th className="p-3 text-right">Mín. / Máx.</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nenhuma mercadoria encontrada.
                                    </td>
                                </tr>
                            )}
                            {items.data.map((item) => (
                                <tr
                                    key={item.id}
                                    className="border-t align-top"
                                >
                                    <td className="p-3">
                                        <Link
                                            href={show(item.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {item.name}
                                        </Link>
                                        <span className="block text-xs text-muted-foreground">
                                            {item.sku ?? 'Sem SKU'}
                                            {item.barcode
                                                ? ` · ${item.barcode}`
                                                : ''}
                                        </span>
                                        <span className="mt-1 flex flex-wrap gap-1">
                                            {item.is_low_stock && (
                                                <Badge variant="destructive">
                                                    Abaixo do mínimo
                                                </Badge>
                                            )}
                                            {item.is_above_max && (
                                                <Badge variant="outline">
                                                    Acima do máximo
                                                </Badge>
                                            )}
                                            {!item.is_active && (
                                                <Badge variant="secondary">
                                                    Inativa
                                                </Badge>
                                            )}
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        {item.category ?? '—'}
                                    </td>
                                    {filters.warehouse !== null && (
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {formatDecimal(
                                                item.warehouse_quantity ?? '0',
                                            )}{' '}
                                            {item.unit.code}
                                        </td>
                                    )}
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatDecimal(item.balance)}{' '}
                                        {item.unit.code}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatDecimal(item.reserved)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatDecimal(item.available)}
                                    </td>
                                    <td className="p-3 text-right whitespace-nowrap">
                                        {formatDecimal(item.minimum_stock)} /{' '}
                                        {item.maximum_stock === null
                                            ? '—'
                                            : formatDecimal(item.maximum_stock)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination links={items.links} />
            </div>
        </>
    );
}

StockItems.layout = {
    breadcrumbs: [inventoryBreadcrumb, { title: 'Mercadorias', href: index() }],
};
