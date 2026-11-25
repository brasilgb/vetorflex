import { Head, Link } from '@inertiajs/react';
import ConfirmButton from '@/components/confirm-button';
import Heading from '@/components/heading';
import ImageGallery from '@/components/products/image-gallery';
import MovementDialog from '@/components/products/movement-dialog';
import MovementTable from '@/components/products/movement-table';
import VariantDialog from '@/components/products/variant-dialog';
import VariantGenerator from '@/components/products/variant-generator';
import type { Tab } from '@/components/tabs';
import Tabs from '@/components/tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTenant } from '@/hooks/use-tenant';
import { formatDecimal, formatMoney, formatPercentage } from '@/lib/decimal';
import { deactivate, edit, index, reactivate } from '@/routes/products';
import type {
    Movement,
    Option,
    PriceChange,
    Product,
    ProductImage,
    ProductVariant,
} from '@/types';

const PRICE_FIELDS: Record<string, string> = {
    cost_price: 'Custo',
    sale_price: 'Venda',
    promotional_price: 'Promocional',
};

function Details({ items }: { items: [string, string | null][] }) {
    const filled = items.filter(([, value]) => value !== null && value !== '');

    if (filled.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Nenhuma informação complementar.
            </p>
        );
    }

    return (
        <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            {filled.map(([label, value]) => (
                <div key={label}>
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="whitespace-pre-line">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function dimensionsOf(variant: ProductVariant): string | null {
    const sizes = [variant.height, variant.width, variant.length];

    return sizes.every((size) => size === null)
        ? null
        : `${sizes.map((size) => (size === null ? '—' : formatDecimal(size))).join(' × ')} cm`;
}

function VariantCard({
    product,
    variant,
    barcodeTypes,
}: {
    product: Product;
    variant: ProductVariant;
    barcodeTypes: Option[];
}) {
    const { can } = useTenant();

    return (
        <div className="space-y-4 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    {variant.label !== null && (
                        <span className="font-medium">{variant.label}</span>
                    )}
                    {!variant.is_active && (
                        <Badge variant="outline">Inativa</Badge>
                    )}
                    {variant.is_active && variant.is_low_stock && (
                        <Badge variant="destructive">Estoque baixo</Badge>
                    )}
                </div>
                {product.type === 'variable' && can('products.update') && (
                    <VariantDialog
                        product={product}
                        variant={variant}
                        barcodeTypes={barcodeTypes}
                    />
                )}
            </div>
            <Details
                items={[
                    ['Preço de venda', formatMoney(variant.sale_price)],
                    [
                        'Preço promocional',
                        variant.promotional_price === null
                            ? null
                            : formatMoney(variant.promotional_price),
                    ],
                    [
                        'Preço de custo',
                        variant.cost_price === undefined
                            ? null
                            : formatMoney(variant.cost_price),
                    ],
                    [
                        'Margem / Markup',
                        variant.margin === undefined
                            ? null
                            : `${formatPercentage(variant.margin)} / ${formatPercentage(variant.markup)}`,
                    ],
                    ['SKU', variant.sku],
                    ['Código de barras', variant.barcode],
                    [
                        'Peso',
                        variant.weight === null
                            ? null
                            : `${formatDecimal(variant.weight)} kg`,
                    ],
                    ['Altura × Largura × Comprimento', dimensionsOf(variant)],
                ]}
            />
        </div>
    );
}

function StockCard({
    product,
    variant,
}: {
    product: Product;
    variant: ProductVariant;
}) {
    const { can } = useTenant();

    return (
        <div className="flex flex-wrap items-center justify-between gap-4 rounded-lg border p-4">
            <Details
                items={[
                    ['Variação', variant.label],
                    [
                        'Saldo',
                        `${formatDecimal(variant.balance ?? '0')} ${product.unit.code}`,
                    ],
                    ['Estoque mínimo', formatDecimal(variant.minimum_stock)],
                ]}
            />
            {can('products.adjust_inventory') &&
                product.is_active &&
                variant.is_active && (
                    <MovementDialog
                        item={{
                            id: variant.id,
                            name: [product.name, variant.label]
                                .filter(Boolean)
                                .join(' - '),
                            unit: product.unit.code,
                            balance: variant.balance ?? null,
                        }}
                    />
                )}
        </div>
    );
}

function PriceHistory({ changes }: { changes: PriceChange[] }) {
    const { formatDateTime } = useTenant();

    if (changes.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Nenhuma alteração de preço registrada.
            </p>
        );
    }

    return (
        <ul className="divide-y text-sm">
            {changes.map((change) => (
                <li
                    key={change.id}
                    className="flex flex-wrap justify-between gap-2 py-2"
                >
                    <span>
                        {[change.variant, PRICE_FIELDS[change.field]]
                            .filter(Boolean)
                            .join(' · ')}
                        : {formatMoney(change.old_value)} →{' '}
                        {formatMoney(change.new_value)}
                    </span>
                    <span className="text-muted-foreground">
                        {change.user_name ?? 'Sistema'} ·{' '}
                        {formatDateTime(change.changed_at)}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export default function ShowProduct({
    product,
    variants,
    images,
    priceChanges,
    movements,
    barcodeTypes,
}: {
    product: Product;
    variants: ProductVariant[];
    images: ProductImage[];
    priceChanges: PriceChange[];
    movements: Movement[];
    barcodeTypes: Option[];
}) {
    const { can } = useTenant();
    const sellableUnits = variants.map((variant) => (
        <VariantCard
            key={variant.id}
            product={product}
            variant={variant}
            barcodeTypes={barcodeTypes}
        />
    ));
    const tabs: Tab[] = [
        {
            key: 'general',
            label: 'Dados gerais',
            content: (
                <Details
                    items={[
                        ['Categoria', product.category],
                        ['Marca', product.brand],
                        [
                            'Unidade',
                            `${product.unit.name} (${product.unit.code})`,
                        ],
                        ['Código interno', product.internal_code],
                        [
                            'Referência do fabricante',
                            product.manufacturer_reference,
                        ],
                        ['Descrição', product.description],
                        ['Observações', product.notes],
                    ]}
                />
            ),
        },
        product.type === 'variable'
            ? {
                  key: 'variants',
                  label: 'Variações',
                  content: (
                      <>
                          {can('products.update') && (
                              <VariantGenerator productId={product.id} />
                          )}
                          {variants.length === 0 && (
                              <p className="text-sm text-muted-foreground">
                                  Nenhuma variação cadastrada.
                              </p>
                          )}
                          {sellableUnits}
                      </>
                  ),
              }
            : { key: 'prices', label: 'Preços', content: sellableUnits },
        ...(product.controls_stock && can('products.view_inventory')
            ? [
                  {
                      key: 'stock',
                      label: 'Estoque',
                      content: (
                          <>
                              {variants.map((variant) => (
                                  <StockCard
                                      key={variant.id}
                                      product={product}
                                      variant={variant}
                                  />
                              ))}
                              <h3 className="font-medium">
                                  Últimas movimentações
                              </h3>
                              <MovementTable movements={movements} />
                          </>
                      ),
                  },
              ]
            : []),
        {
            key: 'images',
            label: 'Imagens',
            content: (
                <ImageGallery
                    product={product}
                    variants={variants}
                    images={images}
                />
            ),
        },
        ...(product.type === 'service'
            ? []
            : [
                  {
                      key: 'fiscal',
                      label: 'Informações fiscais',
                      content: (
                          <>
                              <Details
                                  items={[
                                      ['NCM', product.ncm],
                                      ['CEST', product.cest],
                                      [
                                          'Origem da mercadoria',
                                          product.merchandise_origin,
                                      ],
                                  ]}
                              />
                              <p className="text-xs text-muted-foreground">
                                  Dados informativos: não garantem conformidade
                                  fiscal.
                              </p>
                          </>
                      ),
                  },
              ]),
        {
            key: 'history',
            label: 'Histórico',
            content: <PriceHistory changes={priceChanges} />,
        },
    ];

    return (
        <>
            <Head title={product.name} />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="space-y-2">
                        <Heading
                            variant="small"
                            title={product.name}
                            description={product.short_description ?? undefined}
                        />
                        <div className="flex gap-2">
                            <Badge variant="secondary">
                                {product.type_label}
                            </Badge>
                            {!product.is_active && (
                                <Badge variant="outline">Inativo</Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can('products.update') && (
                            <Button asChild>
                                <Link href={edit(product.id)}>Editar</Link>
                            </Button>
                        )}
                        {product.is_active && can('products.deactivate') && (
                            <ConfirmButton
                                route={deactivate(product.id)}
                                title="Inativar produto"
                                description="O cadastro e o histórico são preservados, mas o item deixa de ser oferecido e movimentado."
                                confirmLabel="Inativar"
                            >
                                Inativar
                            </ConfirmButton>
                        )}
                        {!product.is_active && can('products.reactivate') && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={reactivate(product.id)}
                                    as="button"
                                    preserveScroll
                                >
                                    Reativar
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <Tabs label="Seções do produto" tabs={tabs} />
            </div>
        </>
    );
}

ShowProduct.layout = { breadcrumbs: [{ title: 'Produtos', href: index() }] };
