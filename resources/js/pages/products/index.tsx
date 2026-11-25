import { Head, Link, useForm } from '@inertiajs/react';
import { ImageOff, Plus } from 'lucide-react';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import type { ProductIndicators } from '@/components/products/indicators';
import Indicators from '@/components/products/indicators';
import QuickCreateDialog from '@/components/products/quick-create-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTenant } from '@/hooks/use-tenant';
import { formatDecimal, formatMoney } from '@/lib/decimal';
import {
    create,
    exportMethod as exportProducts,
    index,
    show,
} from '@/routes/products';
import { create as createImport } from '@/routes/products/imports';
import type {
    NamedRecord,
    Option,
    Paginated,
    Product,
    ProductVariant,
} from '@/types';

type ListedProduct = Product & {
    image_url: string | null;
    variants: ProductVariant[];
};

type Filters = Partial<
    Record<
        'search' | 'type' | 'category' | 'brand' | 'active' | 'stock',
        string | null
    >
>;

function priceOf({ type, variants }: ListedProduct): string {
    if (type === 'variable') {
        return `${variants.length} ${variants.length === 1 ? 'variação' : 'variações'}`;
    }

    return formatMoney(
        variants[0]?.promotional_price ?? variants[0]?.sale_price,
    );
}

function stockOf({ type, variants, unit }: ListedProduct): string | null {
    const balance = variants[0]?.balance;

    return type === 'variable' || balance === undefined
        ? null
        : `${formatDecimal(balance)} ${unit.code}`;
}

export default function Products({
    products,
    filters,
    types,
    categories,
    brands,
    indicators,
}: {
    indicators: ProductIndicators;
    products: Paginated<ListedProduct>;
    filters: Filters;
    types: Option[];
    categories: NamedRecord[];
    brands: NamedRecord[];
}) {
    const { can } = useTenant();
    const form = useForm({
        search: filters.search ?? '',
        type: filters.type ?? '',
        category: filters.category ?? '',
        brand: filters.brand ?? '',
        active: filters.active ?? '',
        stock: filters.stock ?? '',
    });
    const { data, setData } = form;

    return (
        <>
            <Head title="Produtos" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Produtos e serviços"
                        description="Catálogo centralizado da empresa ativa."
                    />
                    <div className="flex flex-wrap gap-2">
                        {can('products.export') && (
                            <Button variant="outline" asChild>
                                <a
                                    href={exportProducts.url({
                                        query: filters,
                                    })}
                                >
                                    Exportar CSV
                                </a>
                            </Button>
                        )}
                        {can('products.import') && (
                            <Button variant="outline" asChild>
                                <Link href={createImport()}>Importar CSV</Link>
                            </Button>
                        )}
                        {can('products.create') && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={create()}>
                                        <Plus /> Cadastro completo
                                    </Link>
                                </Button>
                                <QuickCreateDialog />
                            </>
                        )}
                    </div>
                </div>

                <Indicators indicators={indicators} />

                <form
                    className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.get(index.url(), {
                            preserveState: true,
                            preserveScroll: true,
                        });
                    }}
                >
                    <Input
                        className="sm:col-span-2"
                        type="search"
                        aria-label="Pesquisar"
                        placeholder="Nome, código, SKU ou código de barras"
                        value={data.search}
                        onChange={(event) =>
                            setData('search', event.target.value)
                        }
                    />
                    <NativeSelect
                        aria-label="Tipo"
                        value={data.type}
                        onChange={(event) =>
                            setData('type', event.target.value)
                        }
                    >
                        <option value="">Todos os tipos</option>
                        {types.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Categoria"
                        value={data.category}
                        onChange={(event) =>
                            setData('category', event.target.value)
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
                        aria-label="Marca"
                        value={data.brand}
                        onChange={(event) =>
                            setData('brand', event.target.value)
                        }
                    >
                        <option value="">Todas as marcas</option>
                        {brands.map((brand) => (
                            <option key={brand.id} value={brand.id}>
                                {brand.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="Situação"
                        value={data.active}
                        onChange={(event) =>
                            setData('active', event.target.value)
                        }
                    >
                        <option value="">Ativos e inativos</option>
                        <option value="1">Ativos</option>
                        <option value="0">Inativos</option>
                    </NativeSelect>
                    <div className="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-6">
                        {can('products.view_inventory') && (
                            <NativeSelect
                                aria-label="Estoque"
                                className="w-auto"
                                value={data.stock}
                                onChange={(event) =>
                                    setData('stock', event.target.value)
                                }
                            >
                                <option value="">Qualquer estoque</option>
                                <option value="controlled">
                                    Estoque controlado
                                </option>
                                <option value="low">Abaixo do mínimo</option>
                                <option value="out">Sem estoque</option>
                            </NativeSelect>
                        )}
                        <Button variant="outline" disabled={form.processing}>
                            Filtrar
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()} preserveState={false}>
                                Limpar
                            </Link>
                        </Button>
                    </div>
                </form>

                <div className="divide-y rounded-lg border">
                    {products.data.length === 0 && (
                        <p className="p-6 text-sm text-muted-foreground">
                            Nenhum produto encontrado.
                        </p>
                    )}
                    {products.data.map((product) => {
                        const stock = stockOf(product);

                        return (
                            <Link
                                key={product.id}
                                href={show(product.id)}
                                className="flex items-center gap-4 p-4 hover:bg-muted/50"
                            >
                                <div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                    {product.image_url === null ? (
                                        <ImageOff className="size-5 text-muted-foreground" />
                                    ) : (
                                        <img
                                            src={product.image_url}
                                            alt=""
                                            loading="lazy"
                                            className="size-full object-cover"
                                        />
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        {product.name}
                                    </p>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {[
                                            product.type_label,
                                            product.category,
                                            product.brand,
                                            product.type === 'variable'
                                                ? null
                                                : product.variants[0]?.sku,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1 text-sm">
                                    <span>{priceOf(product)}</span>
                                    {stock !== null && (
                                        <span className="text-muted-foreground">
                                            {stock}
                                        </span>
                                    )}
                                    <div className="flex gap-1">
                                        {product.variants.some(
                                            ({ is_active, is_low_stock }) =>
                                                is_active && is_low_stock,
                                        ) && (
                                            <Badge variant="destructive">
                                                Estoque baixo
                                            </Badge>
                                        )}
                                        {!product.is_active && (
                                            <Badge variant="outline">
                                                Inativo
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                            </Link>
                        );
                    })}
                </div>

                <Pagination links={products.links} />
            </div>
        </>
    );
}

Products.layout = { breadcrumbs: [{ title: 'Produtos', href: index() }] };
