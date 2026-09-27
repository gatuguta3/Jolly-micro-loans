<?php
$pageTitle = 'Payments';     // capital C, just cosmetic
$activeNav = 'payments';
ob_start();
?>


    <div class="page-heading">
      <div class="page-heading-copy">
        <span class="page-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
        <div>
          <p class="eyebrow mb-1">Overview</p>
          <h1 class="h3 mb-1">Payments</h1>
          <p class="text-muted mb-0">Monitor payments, principal collected, interest, and outstanding balances.</p>
        </div>
      </div>
      <div class="heading-actions">
        <button class="btn btn-outline-secondary btn-sm" type="button">
          <i class="bi bi-download" aria-hidden="true"></i> Export
        </button>
        <button class="btn btn-primary btn-sm" type="button">
          <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Create Report
        </button>
      </div>
    </div>

    <section class="row g-3 mt-1" aria-label="Dashboard metrics">
      <div class="col-12 col-sm-6 col-xl-3">
        <article class="metric-card metric-primary">
          <div class="metric-top">
            <span class="metric-label">Total Payments</span>
            <span class="metric-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
          </div>
          <div class="metric-value">Ksh 248,500</div>
          <div class="metric-meta">
            <span class="text-success">+12.5%</span>
            <span>from last month</span>
          </div>
        </article>
      </div>

      <div class="col-12 col-sm-6 col-xl-3">
        <article class="metric-card metric-success">
          <div class="metric-top">
            <span class="metric-label">Principal Paid</span>
            <span class="metric-icon"><i class="bi bi-bank" aria-hidden="true"></i></span>
          </div>
          <div class="metric-value">Ksh 198,400</div>
          <div class="metric-meta">
            <span class="text-success">+8.2%</span>
            <span>principal collected</span>
          </div>
        </article>
      </div>

      <div class="col-12 col-sm-6 col-xl-3">
        <article class="metric-card metric-warning">
          <div class="metric-top">
            <span class="metric-label">Interest Collected</span>
            <span class="metric-icon"><i class="bi bi-percent" aria-hidden="true"></i></span>
          </div>
          <div class="metric-value">Ksh 50,100</div>
          <div class="metric-meta">
            <span class="text-success">+5.1%</span>
            <span>interest earned</span>
          </div>
        </article>
      </div>

      <div class="col-12 col-sm-6 col-xl-3">
        <article class="metric-card metric-danger">
          <div class="metric-top">
            <span class="metric-label">Pending Payments</span>
            <span class="metric-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
          </div>
          <div class="metric-value">14</div>
          <div class="metric-meta">
            <span class="text-danger">3 overdue</span>
            <span>need follow-up</span>
          </div>
        </article>
      </div>
    </section>

    <section class="panel mt-3">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-people" aria-hidden="true"></i>
            <span>Recent Payments</span>
          </h2>
          <p class="text-muted mb-0">Latest payment activity across the workspace.</p>
        </div>
        <div class="heading-actions">
          <button class="btn btn-outline-secondary btn-sm" type="button">
            <i class="bi bi-download" aria-hidden="true"></i> Export
          </button>
          <a class="btn btn-primary btn-sm" href="payment-registration.html">
            <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> New Payment
          </a>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Payment Id</th>
              <th scope="col">Client</th>
              <th scope="col">Loan Id</th>
              <th scope="col">Amount Paid</th>
              <th scope="col">Date Paid</th>
              <th scope="col">Mode of payment</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>#1001</td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">
                  <div>
                    <p class="fw-semibold mb-0">Rafi Khan</p>
                    <p class="text-muted small mb-0">rafi@example.com</p>
                  </div>
                </div>
              </td>
              <td>#1002</td>
              <td>Ksh 10000</td>
              <td>Feb 03, 2026</td>
              <td><span class="badge text-bg-danger">Mpesa</span></td>
              <td class="text-end"><a class="btn btn-light btn-sm" href="payment-view.html">View</a></td>
            </tr>

            <tr>
              <td>#1001</td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">
                  <div>
                    <p class="fw-semibold mb-0">Rafi Khan</p>
                    <p class="text-muted small mb-0">rafi@example.com</p>
                  </div>
                </div>
              </td>
              <td>#1002</td>
              <td>Ksh 10000</td>
              <td>Feb 03, 2026</td>
              <td><span class="badge text-bg-danger">Mpesa</span></td>
              <td class="text-end"><a class="btn btn-light btn-sm" href="payment-view.html">View</a></td>
            </tr>

            <tr>
              <td>#1001</td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">
                  <div>
                    <p class="fw-semibold mb-0">Rafi Khan</p>
                    <p class="text-muted small mb-0">rafi@example.com</p>
                  </div>
                </div>
              </td>
              <td>#1002</td>
              <td>Ksh 10000</td>
              <td>Feb 03, 2026</td>
              <td><span class="badge text-bg-danger">Mpesa</span></td>
              <td class="text-end"><a class="btn btn-light btn-sm" href="payment-view.html">View</a></td>
            </tr>

            <tr>
              <td>#1001</td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">
                  <div>
                    <p class="fw-semibold mb-0">Rafi Khan</p>
                    <p class="text-muted small mb-0">rafi@example.com</p>
                  </div>
                </div>
              </td>
              <td>#1002</td>
              <td>Ksh 10000</td>
              <td>Feb 03, 2026</td>
              <td><span class="badge text-bg-danger">Mpesa</span></td>
              <td class="text-end"><a class="btn btn-light btn-sm" href="payment-view.html">View</a></td>
            </tr>

            <tr>
              <td>#1001</td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm" src="../assets/images/avatar/avatar-1.jpg" alt="Sarah Ahmed">
                  <div>
                    <p class="fw-semibold mb-0">Rafi Khan</p>
                    <p class="text-muted small mb-0">rafi@example.com</p>
                  </div>
                </div>
              </td>
              <td>#1002</td>
              <td>Ksh 10000</td>
              <td>Feb 03, 2026</td>
              <td><span class="badge text-bg-danger">Mpesa</span></td>
              <td class="text-end"><a class="btn btn-light btn-sm" href="payment-view.html">View</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
 

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';   // ✅ slash added