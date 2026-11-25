import FormField from '@/components/form-field';
import NativeSelect from '@/components/native-select';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTenant } from '@/hooks/use-tenant';
import {
    formatPercentage,
    parseDecimal,
    profitability,
    toDecimalInput,
} from '@/lib/decimal';
import type { Option, ProductVariant } from '@/types';

export type SellableUnitData = {
    sku: string;
    generate_sku: boolean;
    barcode: string;
    barcode_type: string;
    cost_price: string;
    sale_price: string;
    promotional_price: string;
    minimum_stock: string;
    weight: string;
    height: string;
    width: string;
    length: string;
};

const DIMENSIONS = [
    { field: 'weight', label: 'Peso (kg)' },
    { field: 'height', label: 'Altura (cm)' },
    { field: 'width', label: 'Largura (cm)' },
    { field: 'length', label: 'Comprimento (cm)' },
] as const;

export function sellableUnitData(
    variant: ProductVariant | null,
): SellableUnitData {
    return {
        sku: variant?.sku ?? '',
        generate_sku: false,
        barcode: variant?.barcode ?? '',
        barcode_type: variant?.barcode_type ?? 'gtin',
        cost_price: toDecimalInput(variant?.cost_price, 2),
        sale_price: toDecimalInput(variant?.sale_price, 2),
        promotional_price: toDecimalInput(variant?.promotional_price, 2),
        minimum_stock: toDecimalInput(variant?.minimum_stock),
        weight: toDecimalInput(variant?.weight),
        height: toDecimalInput(variant?.height),
        width: toDecimalInput(variant?.width),
        length: toDecimalInput(variant?.length),
    };
}

export function normalizeSellableUnit<T extends SellableUnitData>(data: T): T {
    return {
        ...data,
        cost_price: parseDecimal(data.cost_price),
        sale_price: parseDecimal(data.sale_price),
        promotional_price: parseDecimal(data.promotional_price),
        minimum_stock: parseDecimal(data.minimum_stock),
        weight: parseDecimal(data.weight),
        height: parseDecimal(data.height),
        width: parseDecimal(data.width),
        length: parseDecimal(data.length),
    };
}

export default function SellableUnitFields({
    data,
    errors,
    barcodeTypes,
    isPhysical,
    controlsStock,
    onChange,
}: {
    data: SellableUnitData;
    errors: Partial<Record<keyof SellableUnitData, string>>;
    barcodeTypes: Option[];
    isPhysical: boolean;
    controlsStock: boolean;
    onChange: (changes: Partial<SellableUnitData>) => void;
}) {
    const { can } = useTenant();
    const canViewCosts = can('products.view_cost');
    const canManagePrices = can('products.manage_prices');
    const { margin, markup } = profitability(
        data.cost_price,
        data.sale_price,
        data.promotional_price,
    );

    return (
        <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-3">
                <FormField id="sku" label="SKU" error={errors.sku}>
                    <Input
                        id="sku"
                        value={data.sku}
                        onChange={(event) =>
                            onChange({ sku: event.target.value })
                        }
                    />
                    {data.sku.trim() === '' && (
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="generate_sku"
                                checked={data.generate_sku}
                                onCheckedChange={(checked) =>
                                    onChange({ generate_sku: checked === true })
                                }
                            />
                            <Label
                                htmlFor="generate_sku"
                                className="font-normal"
                            >
                                Gerar automaticamente
                            </Label>
                        </div>
                    )}
                </FormField>
                <FormField
                    id="barcode"
                    label="Código de barras"
                    error={errors.barcode}
                >
                    <Input
                        id="barcode"
                        value={data.barcode}
                        onChange={(event) =>
                            onChange({ barcode: event.target.value })
                        }
                        onKeyDown={(event) => {
                            // Leitores enviam Enter após o código; isso não deve salvar o cadastro.
                            if (event.key === 'Enter') {
                                event.preventDefault();
                            }
                        }}
                    />
                </FormField>
                <FormField
                    id="barcode_type"
                    label="Tipo do código"
                    error={errors.barcode_type}
                >
                    <NativeSelect
                        id="barcode_type"
                        value={data.barcode_type}
                        onChange={(event) =>
                            onChange({ barcode_type: event.target.value })
                        }
                    >
                        {barcodeTypes.map((type) => (
                            <option key={type.value} value={type.value}>
                                {type.label}
                            </option>
                        ))}
                    </NativeSelect>
                </FormField>
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                {canViewCosts && (
                    <FormField
                        id="cost_price"
                        label="Preço de custo (R$)"
                        error={errors.cost_price}
                    >
                        <Input
                            id="cost_price"
                            inputMode="decimal"
                            disabled={!can('products.manage_cost')}
                            value={data.cost_price}
                            onChange={(event) =>
                                onChange({ cost_price: event.target.value })
                            }
                        />
                    </FormField>
                )}
                <FormField
                    id="sale_price"
                    label="Preço de venda (R$)"
                    error={errors.sale_price}
                >
                    <Input
                        id="sale_price"
                        inputMode="decimal"
                        disabled={!canManagePrices}
                        value={data.sale_price}
                        onChange={(event) =>
                            onChange({ sale_price: event.target.value })
                        }
                    />
                </FormField>
                <FormField
                    id="promotional_price"
                    label="Preço promocional (R$)"
                    error={errors.promotional_price}
                >
                    <Input
                        id="promotional_price"
                        inputMode="decimal"
                        disabled={!canManagePrices}
                        value={data.promotional_price}
                        onChange={(event) =>
                            onChange({ promotional_price: event.target.value })
                        }
                    />
                </FormField>
                {canViewCosts && (
                    <p className="text-sm text-muted-foreground md:col-span-3">
                        Margem sobre a venda: {formatPercentage(margin)} ·
                        Markup sobre o custo: {formatPercentage(markup)}
                    </p>
                )}
            </div>

            {isPhysical && (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {controlsStock && (
                        <FormField
                            id="minimum_stock"
                            label="Estoque mínimo"
                            error={errors.minimum_stock}
                        >
                            <Input
                                id="minimum_stock"
                                inputMode="decimal"
                                value={data.minimum_stock}
                                onChange={(event) =>
                                    onChange({
                                        minimum_stock: event.target.value,
                                    })
                                }
                            />
                        </FormField>
                    )}
                    {DIMENSIONS.map(({ field, label }) => (
                        <FormField
                            key={field}
                            id={field}
                            label={label}
                            error={errors[field]}
                        >
                            <Input
                                id={field}
                                inputMode="decimal"
                                value={data[field]}
                                onChange={(event) =>
                                    onChange({ [field]: event.target.value })
                                }
                            />
                        </FormField>
                    ))}
                </div>
            )}
        </div>
    );
}
