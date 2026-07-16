import Foundation
import StoreKit
import JoineryKit

/// State for the billing screen: server catalog + summary, StoreKit products,
/// and the purchase/restore flows. Server-authoritative — every StoreKit
/// success is posted to `store/app_store_claim` before the UI reports it.
@MainActor
public final class BillingStore: ObservableObject {
    public enum Phase {
        case loading
        case failed(String)
        case loaded
    }

    @Published public private(set) var phase: Phase = .loading
    @Published public private(set) var catalog: BillingCatalog?
    @Published public private(set) var summary: BillingSummary?
    @Published public private(set) var storeProducts: [String: Product] = [:]
    @Published public private(set) var purchasing = false
    @Published public var actionError: String?
    @Published public var actionMessage: String?

    private let api: BillingAPI

    public init(api: BillingAPI) {
        self.api = api
    }

    public convenience init(client: APIClient) {
        self.init(api: BillingAPI(client: client))
    }

    public func initialLoad() async {
        phase = .loading
        await load()
    }

    public func load() async {
        do {
            async let catalogTask = api.catalog()
            async let summaryTask = api.summary()
            let (catalog, summary) = try await (catalogTask, summaryTask)
            self.catalog = catalog
            self.summary = summary
            if !catalog.plans.isEmpty {
                let ids = catalog.plans.map(\.storeProductID)
                let products = (try? await Product.products(for: ids)) ?? []
                storeProducts = Dictionary(uniqueKeysWithValues: products.map { ($0.id, $0) })
            } else {
                storeProducts = [:]
            }
            phase = .loaded
        } catch let error as JoineryAPIError {
            phase = .failed(error.displayMessage)
        } catch {
            phase = .failed("Could not load plans.")
        }
    }

    /// Run the StoreKit purchase sheet for a plan, then claim the signed
    /// transaction server-side. The tier is granted only when the claim
    /// succeeds.
    public func purchase(_ plan: BillingPlan) async {
        guard let catalog, !purchasing else { return }
        guard let product = storeProducts[plan.storeProductID] else {
            actionError = "This plan is not available right now."
            return
        }
        purchasing = true
        defer { purchasing = false }

        var options: Set<Product.PurchaseOption> = []
        if let token = UUID(uuidString: catalog.appAccountToken) {
            options.insert(.appAccountToken(token))
        }

        do {
            let result = try await product.purchase(options: options)
            switch result {
            case .success(let verification):
                guard case .verified(let transaction) = verification else {
                    actionError = "The purchase could not be verified."
                    return
                }
                let claim = try await api.claim(jws: verification.jwsRepresentation)
                await transaction.finish()
                actionMessage = claim.tier.map { "You're on \($0.name)." } ?? "Purchase complete."
                await load()
            case .userCancelled, .pending:
                break
            @unknown default:
                break
            }
        } catch let error as JoineryAPIError {
            actionError = error.displayMessage
        } catch {
            actionError = "The purchase could not be completed."
        }
    }

    /// Re-claim every current auto-renewable entitlement (restore purchases,
    /// reinstalls, new device). Claims are idempotent server-side.
    public func restorePurchases() async {
        guard !purchasing else { return }
        purchasing = true
        defer { purchasing = false }

        var claimed = 0
        var lastError: String?
        for await result in Transaction.currentEntitlements {
            guard case .verified(let transaction) = result,
                  transaction.productType == .autoRenewable else { continue }
            do {
                _ = try await api.claim(jws: result.jwsRepresentation)
                claimed += 1
            } catch let error as JoineryAPIError {
                lastError = error.displayMessage
            } catch {
                lastError = "A purchase could not be restored."
            }
        }

        if claimed > 0 {
            actionMessage = "Purchases restored."
            await load()
        } else if let lastError {
            actionError = lastError
        } else {
            actionError = "No purchases to restore."
        }
    }
}
