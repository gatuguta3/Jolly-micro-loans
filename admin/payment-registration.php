<?php
$pageTitle = 'Payment registration';     // capital C, just cosmetic
$activeNav = 'payments';
ob_start();
?>


    <div class="page-heading">
      <div class="page-heading-copy">
        <span class="page-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
        <div>
          <p class="eyebrow mb-1">Payments</p>
          <h1 class="h3 mb-1">Record Payment</h1>
          <p class="text-muted mb-0">Record a new payment against an existing loan.</p>
        </div>
      </div>
      <div class="heading-actions">
        <a class="btn btn-outline-secondary btn-sm" href="payments.html">
          <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Payments
        </a>
      </div>
    </div>

    <section class="row g-3">
      <div class="col-12 col-xl-8">
        <form class="panel needs-validation" method="post" enctype="multipart/form-data" novalidate>
          <div class="panel-header">
            <div>
              <h2 class="h5 mb-1 section-title">
                <i class="bi bi-cash-coin" aria-hidden="true"></i>
                <span>Payment Information</span>
              </h2>
              <p class="text-muted mb-0">Select the loan, enter the amount, and attach proof of payment.</p>
            </div>
          </div>

          <div class="row g-3">
            <!-- Client -->
            <div class="col-md-6">
              <label class="form-label" for="client">Client</label>
              <select class="form-select" id="client" name="client" required>
                <option value="">Choose registered client</option>
                <option>Rafi Khan (rafi@example.com)</option>
                <option>Sarah Ahmed (sarah@example.com)</option>
                <option>Grace Wanjiku (grace@example.com)</option>
              </select>
              <div class="invalid-feedback">Please choose a client.</div>
            </div>

            <!-- Loan Id -->
            <div class="col-md-6">
              <label class="form-label" for="loanId">Loan</label>
              <select class="form-select" id="loanId" name="loanId" required>
                <option value="">Choose loan</option>
                <option>#1001 – Personal Loan (Ksh 50,000)</option>
                <option>#1002 – Business Loan (Ksh 250,000)</option>
                <option>#1003 – Auto Loan (Ksh 75,000)</option>
              </select>
              <div class="invalid-feedback">Please choose a loan.</div>
            </div>

            <!-- Amount Paid -->
            <div class="col-md-6">
              <label class="form-label" for="amountPaid">Amount Paid (Ksh)</label>
              <input class="form-control" id="amountPaid" name="amountPaid" type="number" min="1" step="0.01" required>
              <div class="invalid-feedback">Enter the amount paid.</div>
            </div>

            <!-- Date Paid -->
            <div class="col-md-6">
              <label class="form-label" for="datePaid">Date Paid</label>
              <input class="form-control" id="datePaid" name="datePaid" type="date" required>
              <div class="invalid-feedback">Date paid is required.</div>
            </div>

            <!-- Mode of Payment -->
            <div class="col-md-6">
              <label class="form-label" for="mode">Mode of Payment</label>
              <select class="form-select" id="mode" name="mode" required>
                <option value="">Choose mode</option>
                <option value="mpesa">Mpesa</option>
                <option value="bank">Bank Transfer</option>
                <option value="cash">Cash</option>
                <option value="cheque">Cheque</option>
                <option value="card">Card</option>
              </select>
              <div class="invalid-feedback">Choose a payment mode.</div>
            </div>

            <!-- Reference Number -->
            <div class="col-md-6">
              <label class="form-label" for="reference">Reference / Transaction No</label>
              <input class="form-control" id="reference" name="reference" type="text" placeholder="e.g., MPESA-XXXX1234">
              <div class="form-text">Optional. Useful for reconciliation.</div>
            </div>

            <!-- Proof of Payment -->
            <div class="col-12">
              <label class="form-label" for="proof">Proof of Payment (Media Upload)</label>
              <input class="form-control" id="proof" name="proof[]" type="file" accept="image/*,application/pdf" multiple>
              <div class="form-text">Upload screenshots or receipts. Accepted: JPG, PNG, PDF.</div>
              <div id="proofPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
            </div>

            <!-- Notes -->
            <div class="col-12">
              <label class="form-label" for="notes">Notes</label>
              <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Optional notes about this payment"></textarea>
            </div>
          </div>

          <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
            <a class="btn btn-outline-secondary" href="payments.html">Cancel</a>
            <button class="btn btn-primary" type="submit">
              <i class="bi bi-check-circle" aria-hidden="true"></i> Record Payment
            </button>
          </div>
        </form>
      </div>

      <div class="col-12 col-xl-4">
        <div class="panel h-100">
          <h2 class="h5 mb-3 section-title">
            <i class="bi bi-list-check" aria-hidden="true"></i>
            <span>Payment Checklist</span>
          </h2>
          <div class="activity-list">
            <div class="activity-item">
              <span class="activity-dot bg-success"></span>
              <div>
                <p class="mb-1 fw-semibold">Select client</p>
                <p class="text-muted small mb-0">Only registered clients appear here.</p>
              </div>
            </div>
            <div class="activity-item">
              <span class="activity-dot bg-primary"></span>
              <div>
                <p class="mb-1 fw-semibold">Link to a loan</p>
                <p class="text-muted small mb-0">Payments must be linked to a specific loan.</p>
              </div>
            </div>
            <div class="activity-item">
              <span class="activity-dot bg-warning"></span>
              <div>
                <p class="mb-1 fw-semibold">Enter amount</p>
                <p class="text-muted small mb-0">Confirm the amount matches the receipt.</p>
              </div>
            </div>
            <div class="activity-item">
              <span class="activity-dot bg-info"></span>
              <div>
                <p class="mb-1 fw-semibold">Attach proof</p>
                <p class="text-muted small mb-0">Upload Mpesa message or bank slip.</p>
              </div>
            </div>
            <div class="activity-item">
              <span class="activity-dot bg-secondary"></span>
              <div>
                <p class="mb-1 fw-semibold">Submit</p>
                <p class="text-muted small mb-0">The loan balance updates automatically.</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>



<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';   // ✅ slash added